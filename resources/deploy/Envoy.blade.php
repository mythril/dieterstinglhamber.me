@setup
    # Bootstrap composer + the dotenv configurations.
    require __DIR__.'/vendor/autoload.php';
    $dotenv = new Dotenv\Dotenv(__DIR__);

    try {
        $dotenv->load();
        $dotenv->required([
            'DEPLOY_DIR_BASE',
            'DEPLOY_REPOSITORY',
            'DEPLOY_USER',
            'DEPLOY_HOST'
        ])->notEmpty();
    } catch ( Exception $e )  {
        echo $e->getMessage();
        exit;
    }

    function logMessage($message) {
        return "echo '\033[32m" .$message. "\033[0m';\n";
    }

    # Retrieve the values from the config, inform user if there are pieces missing.
    # These 4 values don't have a default value and need to be supplied in the
    # .env config.
    $baseDir    = getenv('DEPLOY_DIR_BASE');
    $repository = getenv('DEPLOY_REPOSITORY');
    $deployUser = getenv('DEPLOY_USER');
    $deployHost = getenv('DEPLOY_HOST');

    if (!strlen($repository) || !strlen($deployUser) || !strlen($deployHost)) {
        echo "Your .env config is missing one of the following values:
DEPLOY_HOST=
DEPLOY_USER=
DEPLOY_DIR_BASE=
DEPLOY_REPOSITORY=
";
        exit;
    }

    # Config variables
    $configReleasesDir    = strlen(getenv('DEPLOY_DIR_RELEASES')) > 0 ? getenv('DEPLOY_DIR_RELEASES') : "releases";
    $configPersistentDir  = strlen(getenv('DEPLOY_DIR_PERSISTENT')) > 0 ? getenv('DEPLOY_DIR_PERSISTENT') : "persistent";
    $currentName          = strlen(getenv('DEPLOY_CURRENT')) > 0 ? getenv('DEPLOY_CURRENT') : "current";
    $deploySshPort        = strlen(getenv('DEPLOY_SSH_PORT')) > 0 ? getenv('DEPLOY_SSH_PORT') : "22";
    $branch               = strlen(getenv('DEPLOY_BRANCH')) > 0 ? getenv('DEPLOY_BRANCH') : "master";

    # Slack config variables
    $slackWebHook         = strlen(getenv('DEPLOY_SLACK_WEBHOOK')) > 0 ? getenv('DEPLOY_SLACK_WEBHOOK') : null;
    $slackChannel         = strlen(getenv('DEPLOY_SLACK_CHANNEL')) > 0 ? getenv('DEPLOY_SLACK_CHANNEL') : null;
    $slackCustomMessage   = strlen(getenv('DEPLOY_SLACK_MESSAGE')) > 0 ? getenv('DEPLOY_SLACK_MESSAGE') : null;

    # HipChat config variables
    $hipChatWebHook       = strlen(getenv('DEPLOY_HIPCHAT_WEBHOOK')) > 0 ? getenv('DEPLOY_HIPCHAT_WEBHOOK') : null;
    $hipChatRoom          = strlen(getenv('DEPLOY_HIPCHAT_ROOM')) > 0 ? getenv('DEPLOY_HIPCHAT_ROOM') : null;
    $hipChatFrom          = strlen(getenv('DEPLOY_HIPCHAT_FROM')) > 0 ? getenv('DEPLOY_HIPCHAT_FROM') : null;
    $hipChatCustomMessage = strlen(getenv('DEPLOY_HIPCHAT_MESSAGE')) > 0 ? getenv('DEPLOY_HIPCHAT_MESSAGE') : null;
    $hipChatColor         = strlen(getenv('DEPLOY_HIPCHAT_COLOR')) > 0 ? getenv('DEPLOY_HIPCHAT_COLOR') : null;

    # Paths
    $releasesDir    = "{$baseDir}/{$configReleasesDir}";
    $persistentDir  = "{$baseDir}/{$configPersistentDir}";
    $configDir      = "{$persistentDir}/config";
    $currentDir     = "{$baseDir}/{$currentName}";
    $newReleaseName = date('Ymd-His');
    $newReleaseDir  = "{$releasesDir}/{$newReleaseName}";
    $user           = get_current_user();
@endsetup

@servers(['local' => '127.0.0.1','remote' => '-A -p '. $deploySshPort .' -l '. $deployUser .' '. $deployHost])

@macro('deploy')
    validateServerEnvironment
    cloneRepository
    runComposer
    runNpm
    generateAssets
    updateSymlinks
    optimizeInstallation
    blessNewRelease
    cleanOldReleases
    finishDeploy
@endmacro

@macro('deploy-code')
    deployOnlyCode
@endmacro

@task('validateServerEnvironment', ['on' => 'remote'])
    {{ logMessage("👀  Testing remote server environment ...") }}
    CONFIG_FILE={{ $configDir }}/env
    HAS_UNMODIFIED_CONFIG=$(grep -Pc 'APP_KEY=$' $CONFIG_FILE || true)
    if [ $HAS_UNMODIFIED_CONFIG -eq 1 ]; then
        # Config not yet completed
        echo "We found this configuration file: '{{ $configDir }}/env'"
        echo "However, it contains Laravel-specific sections that have not yet been completed."
        echo "Please update the configuration file."
        exit 1
    else
        echo "Config OK."
    fi
@endtask

@task('startDeployment', ['on' => 'local'])
    {{ logMessage("🏃  Starting deployment...") }}
    git checkout {{ $branch }}
    git pull origin {{ $branch }}
@endtask

@task('cloneRepository', ['on' => 'remote'])
    {{ logMessage("🌀  Cloning repository...") }}
    [ -d {{ $releasesDir }} ] || mkdir {{ $releasesDir }};
    [ -d {{ $persistentDir }} ] || mkdir {{ $persistentDir }};
    [ -d {{ $persistentDir }}/media ] || mkdir {{ $persistentDir }}/media;
    [ -d {{ $persistentDir }}/storage ] || mkdir {{ $persistentDir }}/storage;
    cd {{ $releasesDir }};

    # Create the release dir
    mkdir {{ $newReleaseDir }};

    # Clone the repo
    git clone --depth 1 -b {{ $branch }} {{ $repository }} {{ $newReleaseName }}

    # Configure sparse checkout
    cd {{ $newReleaseDir }}
    git config core.sparsecheckout true
    echo "*" > .git/info/sparse-checkout
    echo "!storage" >> .git/info/sparse-checkout
    echo "!public/build" >> .git/info/sparse-checkout
    git read-tree -mu HEAD

    # Mark release
    cd {{ $newReleaseDir }}
    echo "{{ $newReleaseName }}" > public/release-name.txt
@endtask

@task('runComposer', ['on' => 'remote'])
    {{ logMessage("🚚  Running Composer...") }}
    cd {{ $newReleaseDir }};
    composer install --prefer-dist --no-scripts --no-dev -q -o;
@endtask

@task('runNpm', ['on' => 'remote'])
    {{ logMessage("📦  Running Npm...") }}
    cd {{ $newReleaseDir }};
    npm config set ignore-engines true
    npm install
@endtask

@task('generateAssets', ['on' => 'remote'])
    {{ logMessage("🌅  Generating assets...") }}
    cd {{ $newReleaseDir }};
    npm run production -- --progress false
@endtask

@task('updateSymlinks', ['on' => 'remote'])
    {{ logMessage("🔗  Updating symlinks to persistent data...") }}
    # Remove the storage directory and replace with persistent data
    rm -rf {{ $newReleaseDir }}/storage;
    cd {{ $newReleaseDir }};
    ln -nfs {{ $persistentDir }}/storage storage;

    # Remove the public/media directory and replace with persistent data
    rm -rf {{ $newReleaseDir }}/public/media;
    cd {{ $newReleaseDir }};
    ln -nfs {{ $persistentDir }}/media public/media;

    # Import the environment config
    cd {{ $newReleaseDir }};
    ln -nfs {{ $configDir }}/env .env;
@endtask

@task('optimizeInstallation', ['on' => 'remote'])
    {{ logMessage("✨  Optimizing installation...") }}
    cd {{ $newReleaseDir }};
    php artisan clear-compiled;
@endtask

@task('backupDatabase', ['on' => 'remote'])
    {{ logMessage("📀  Backing up database...") }}
    cd {{ $newReleaseDir }}
    php artisan | grep 'backup:run' && php artisan backup:run || echo 'Back-up package not configured, skipping.'
@endtask

@task('migrateDatabase', ['on' => 'remote'])
    {{ logMessage("🙈  Migrating database...") }}
    cd {{ $newReleaseDir }};
    php artisan migrate --force;
@endtask

@task('blessNewRelease', ['on' => 'remote'])
    {{ logMessage("🙏  Blessing new release...") }}
    ln -nfs {{ $newReleaseDir }} {{ $currentDir }};
    cd {{ $newReleaseDir }}

    php artisan | grep 'horizon' && php artisan horizon:terminate || true
    php artisan config:clear
    php artisan cache:clear
    php artisan config:cache

    # ~/scripts/reload_php-fpm.sh
    php artisan queue:restart
@endtask

@task('cleanOldReleases', ['on' => 'remote'])
    {{ logMessage("🚾  Cleaning up old releases...") }}
    # Delete all but the 3 most recent.
    cd {{ $releasesDir }}
    ls -dt ./* | tail -n +4 | xargs -d "\n" rm -rf;
@endtask

@task('finishDeploy', ['on' => 'local'])
    {{ logMessage("🚀  Application deployed!") }}
@endtask

@task('deployOnlyCode', ['on' => 'remote'])
    {{ logMessage("💻  Deploying code changes...") }}
    cd {{ $currentDir }}
    git pull origin {{ $branch }}
    php artisan config:clear
    php artisan cache:clear
    php artisan config:cache
    ~/scripts/reload_php-fpm.sh
@endtask

@finished
    if ($slackWebHook && $slackChannel) {
        @slack($slackWebHook, $slackChannel, $slackCustomMessage)
    }

    if ($hipChatWebHook && $hipChatRoom && $hipChatFrom) {
        @hipchat($hipChatWebHook, $hipChatRoom, $hipChatFrom, $hipChatCustomMessage, $hipChatColor)
    }
@endfinished
