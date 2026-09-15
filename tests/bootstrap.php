<?php

declare(strict_types=1);

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';
(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

// Apply the real migrations to a separate SQLite database; no manual test setup.
$kernel = new Kernel('test', true);
$application = new Application($kernel);
$application->setAutoExit(false);
$input = new ArrayInput(['command' => 'doctrine:migrations:migrate', '--no-interaction' => true]);
$input->setInteractive(false);
$output = new BufferedOutput();
if ($application->run($input, $output) !== 0) {
    throw new RuntimeException($output->fetch());
}
$kernel->shutdown();
