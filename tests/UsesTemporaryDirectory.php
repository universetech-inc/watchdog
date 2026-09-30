<?php

declare(strict_types=1);

namespace UniverseTech\Watchdog\Tests;

trait UsesTemporaryDirectory
{
    protected ?string $temporaryDirectory = null;

    protected function temporaryPath(string $name = ''): string
    {
        if ($this->temporaryDirectory === null) {
            $this->temporaryDirectory = sys_get_temp_dir() . '/watchdog-test-' . bin2hex(random_bytes(4));
            mkdir($this->temporaryDirectory);
        }

        return rtrim($this->temporaryDirectory . '/' . $name, '/');
    }

    protected function removeTemporaryDirectory(): void
    {
        if ($this->temporaryDirectory === null) {
            return;
        }

        foreach (glob($this->temporaryDirectory . '/{,.}[!.]*', GLOB_BRACE) ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->temporaryDirectory);
        $this->temporaryDirectory = null;
    }
}
