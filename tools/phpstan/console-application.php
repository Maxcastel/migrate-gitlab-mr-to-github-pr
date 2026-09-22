<?php

declare(strict_types=1);

/*
 * Boots the console application so phpstan-symfony can resolve command
 * metadata (helpers, argument/option types) from the real container.
 */

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__, 2).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__, 2).'/.env');

$kernel = new Kernel((string) ($_SERVER['APP_ENV'] ?? 'dev'), (bool) ($_SERVER['APP_DEBUG'] ?? true));
$kernel->boot();

return new Application($kernel);
