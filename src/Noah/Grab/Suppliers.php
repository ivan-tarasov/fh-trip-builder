<?php

namespace TripBuilder\Noah\Grab;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use TripBuilder\Config;
use TripBuilder\Debug\dBug;
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;

class Suppliers extends AbstractCommand
{
    /**
     * The name of the command.
     *
     * @var string
     */
    protected static $defaultName = 'grab:suppliers';

    /**
     * The command description shown when running `list` command.
     *
     * @var string
     */
    protected static $defaultDescription = 'Grab suppliers logo from Aviasales';

    /**
     * Execute the command
     *
     * @param  $input
     * @param  $output
     * @return int 0 if everything went fine, or an exit code.
     * @throws \Exception
     */
    protected function execute($input, $output): int
    {
        $codes = $this->db->get('airlines', null, 'code');

        $progressBar = new ProgressBar($output, count($codes));
        $progressBar->setBarCharacter('<fg=green>▓</>');
        $progressBar->setEmptyBarCharacter('<fg=default>░</>');
        $progressBar->setProgressCharacter('<fg=green>▓</>');
        $progressBar->setFormat(" %current%/%max% %bar% %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory%\n %message%");
        $progressBar->start();

        foreach ($codes as $code) {
            // URL of the image you want to download
            $imageUrl = sprintf('https://mpics.avs.io/al_square/64/64/%s.png', $code['code']);

            // Folder where you want to save the downloaded image
            $targetFolder = Helper::getRootDir() . '/frontend/images/suppliers/';

            // Extract the filename from the URL
            $filename = basename($imageUrl);

            // Create the target folder if it doesn't exist
            if (!is_dir($targetFolder)) {
                mkdir($targetFolder, 0755, true);
            }

            // Build the complete path to save the image
            $targetPath = $targetFolder . $filename;

            if (! file_exists($targetPath)) {
                // Get the HTTP headers of the URL
                $headers = get_headers($imageUrl);

                if ($headers[0] != 'HTTP/1.1 404 Not Found') {
                    // Download the image using cURL
                    $ch = curl_init($imageUrl);
                    $fp = fopen($targetPath, 'wb');
                    curl_setopt($ch, CURLOPT_FILE, $fp);
                    curl_setopt($ch, CURLOPT_HEADER, 0);
                    curl_exec($ch);
                    curl_close($ch);
                    fclose($fp);
                }
            }

            $progressBar->advance();
        }

        $progressBar->finish();

        return Command::SUCCESS;
    }

}
