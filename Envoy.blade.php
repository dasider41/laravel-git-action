@include('vendor/autoload.php')

@setup
	$dotenv = Dotenv\Dotenv::create(__DIR__);
	try {
		$dotenv->load();
	} catch ( Exception $e )  {
		echo $e->getMessage();
	}

    $server = getenv('DEPLOY_SERVER');
    $repository = getenv('DEPLOY_REPOSITORY');
    $releases_dir = getenv('DEPLOY_RELEASES');
    $app_dir = getenv('DEPLOY_APP');
    $release = date('YmdHis');
    $new_release_dir = $releases_dir .'/'. $release;
@endsetup

@servers(['web' => $server])

@story('deploy')
    clone_repository
    update_symlinks
    run_composer
    {{-- db_migrate --}}
	clear_cache
@endstory

@story('rollback')
    run_rollback
@endstory

@story('list')
    releases_list
@endstory

@story('old_clean_up')
    clean_up_old_releases
@endstory

@task('clone_repository')
    echo 'Cloning repository'
    [ -d {{ $releases_dir }} ] || mkdir {{ $releases_dir }}
    git clone --depth 1 {{ $repository }} {{ $new_release_dir }}
    cd {{ $new_release_dir }}
    git reset --hard {{ $commit }}
@endtask

@task('run_composer')
    echo "Starting deployment ({{ $release }})"
    cd {{ $new_release_dir }}
    chmod 777 -R ./bootstrap/cache
    composer install --prefer-dist --no-scripts -q -o
@endtask

@task('update_symlinks')
    echo "Linking storage directory"
    rm -rf {{ $new_release_dir }}/storage
    ln -nfs {{ $app_dir }}/storage {{ $new_release_dir }}/storage

    echo 'Linking vehicle images'
    ln -nfs {{ $app_dir }}/storage/app/public {{ $new_release_dir }}/public/storage

    echo 'Linking .env file'
    ln -nfs {{ $app_dir }}/.env {{ $new_release_dir }}/.env

    echo 'Linking current release'
    ln -nfs {{ $new_release_dir }} {{ $app_dir }}/current
@endtask

@task('db_migrate')
    echo "Database migration"
    php {{ $new_release_dir }}/artisan migrate --env={{ $env }} --force --no-interaction --seed
@endtask

@task('clear_cache')
    php {{ $new_release_dir }}/artisan view:clear --quiet
    php {{ $new_release_dir }}/artisan cache:clear --quiet
    php {{ $new_release_dir }}/artisan config:cache --quiet
    echo "Cache cleared"
@endtask

@task('run_rollback')
    cd {{ $releases_dir }}
    ln -nfs $(find . -maxdepth 1 -name "20*" | sort  | tail -n 2 | head -n1) {{ $app_dir }}/current
    echo "Rolled back to $(find . -maxdepth 1 -name "20*" | sort  | tail -n 2 | head -n1)"
@endtask

@task('release_branch')
    cd {{ $releases_dir }}
    ln -nfs {{ $branch }} {{ $app_dir }}/current
    echo "Rolled back to release {{ $branch }}"
@endtask

@task('releases_list')
    cd {{ $releases_dir }}
    ls -al
@endtask

@task('clean_up_old_releases')
    echo "Cleaned up old deployments"
    cd {{ $releases_dir }}
    find . -maxdepth 1 -name "20*" | sort | head -n -5 | xargs rm -Rf
@endtask

{{-- TODO:: message for slack
@after
    @slack('hook', 'channel', 'message')
@endafter --}}