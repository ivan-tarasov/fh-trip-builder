<?php

namespace TripBuilder\Noah\Grab;

use Exception;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TripBuilder\Helper;
use TripBuilder\Noah\AbstractCommand;

#[AsCommand(
    name: 'grab:suppliers',
    description: 'Grab suppliers logo from Aviasales',
    hidden: false,
)]

class Suppliers extends AbstractCommand
{
    private const IMAGE_URL = 'https://mpics.avs.io/al_square/64/64/%s.png';
    private const LOCAL_IMAGE_PATH = '/frontend/images/suppliers/';

    /**
     * @throws Exception
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
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
            $imageUrl = sprintf(self::IMAGE_URL, $code['code']);

            // Folder where you want to save the downloaded image
            $targetFolder = Helper::getRootDir() . self::LOCAL_IMAGE_PATH;

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

                if ($headers[0] !== 'HTTP/1.1 404 Not Found') {
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
