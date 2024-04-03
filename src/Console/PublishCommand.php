<?php

namespace Chatsys\Console;

use Illuminate\Console\Command;

class PublishCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'Chatsys:publish {--force : Overwrite any existing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish all of the Chatsys assets';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        if($this->option('force')){
            $this->call('vendor:publish', [
                '--tag' => 'Chatsys-config',
                '--force' => true,
            ]);

            $this->call('vendor:publish', [
                '--tag' => 'Chatsys-migrations',
                '--force' => true,
            ]);

            $this->call('vendor:publish', [
                '--tag' => 'Chatsys-models',
                '--force' => true,
            ]);
        }

        $this->call('vendor:publish', [
            '--tag' => 'Chatsys-views',
            '--force' => true,
        ]);

        $this->call('vendor:publish', [
            '--tag' => 'Chatsys-assets',
            '--force' => true,
        ]);
    }
}
