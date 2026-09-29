<?php
declare(strict_types=1);

namespace TypeDock\Contract;

use TypeDock\Core\PluginContext;

interface PluginInterface
{
    public function register(PluginContext $ctx): void;
    public function getName(): string;
    public function getVersion(): string;
    public function provides(): array;
}
