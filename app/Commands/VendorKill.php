<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;

class VendorKill extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'process {path? : The path to search for vendor directories}
                                    {--maxdepth=2 : The maximum depth to search for vendor directories}';

    /**
     * The console command description.
     *
     * @var string
     */

    protected $description = 'Delete composer vendor directories.';

    public function handle()
    {
        // Use the provided path or the current directory if none was provided
        $search_path = $this->argument('path') ?? getcwd();

        // Find all vendor directories within the search path
        $process = new Process(['find', $search_path, '-maxdepth', $this->option('maxdepth'), '-type', 'd', '-name', 'vendor']);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        $vendor_dirs = explode(PHP_EOL, trim($process->getOutput()));

        $total_size_human = $this->getVendorTotalSize($vendor_dirs);

        $this->newLine();

        // Show the total size of the vendor directories
        $this->showTotal($total_size_human, $vendor_dirs);
        $counter =  1;
        // List the vendor directories with their sizes and project names
        foreach ($vendor_dirs as $index => $dir) {
            $size = shell_exec("du -sh $dir | cut -f1");
            $project_name = basename(dirname($dir));
            $this->components->twoColumnDetail('<options=bold>' . "{$counter}: $project_name" . '</>', '<options=bold>' . $size . '</>');
            $this->components->twoColumnDetail('Path', $dir);
            $this->newLine();
            $counter++;
        }

        // Show the total size of the vendor directories again for convenience
        $this->showTotal($total_size_human, $vendor_dirs);

        // Prompt the user to select directories to delete
        $delete_numbers = $this->ask('Enter the numbers of the directories to delete, separated by spaces:');
        $delete_numbers = explode(' ', $delete_numbers);

        // Delete the selected directories
        foreach ($delete_numbers as $num) {
            $dir_to_delete = $vendor_dirs[$num -  1];
            shell_exec("rm -rf {$dir_to_delete}");
            $this->info("Deleted {$dir_to_delete}");
        }
    }

    protected function getVendorTotalSize(array $vendor_dirs): string
    {
        // Calculate the total size of vendor directories
        $total_size =  0;
        foreach ($vendor_dirs as $dir) {
            $size = shell_exec("du -s $dir | cut -f1");
            $total_size += $size;
        }

        // Convert the total size to a human-readable format
        return $this->formatSize($total_size);
    }

    // Helper function to format the size
    private function formatSize($size) {
        $units = ['KB', 'MB', 'GB', 'TB'];

        for ($i =  0; $size >  1024; $i++) {
            $size /=  1024;
        }

        return round($size,  2) . ' ' . $units[$i];
    }

    protected function showTotal(string $total_size_human, array $vendor_dirs): void
    {
        $this->components->twoColumnDetail('<fg=green;options=bold>Found ' . count($vendor_dirs) . ' vendor directories</>', '<fg=green;options=bold>' . $total_size_human . '</>');
        $this->newLine();
    }
}
