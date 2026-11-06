<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\User;

/**
 * One capability the model may request. A tool never trusts its arguments (the registry
 * validates them against `parameters()` first) and never decides authorization by itself:
 * `available()` plus the registry's module/permission gates run before every execution,
 * and `execute()` reads only through the app's existing scoped queries and services.
 */
interface AiTool
{
    public function name(): string;

    public function description(): string;

    /** @return array<string,mixed> JSON Schema (type=object) for the arguments */
    public function parameters(): array;

    /** @return list<string> plan modules that must all be enabled for the tenant */
    public function modules(): array;

    /** 'tms' | 'hrms' | null: the product that must be enabled for the tenant */
    public function product(): ?string;

    /** Tenant permission slug the caller must hold, or null when row scoping alone decides */
    public function permission(): ?string;

    /** Extra, per-user availability (e.g. the caller has an employment record) */
    public function available(User $user): bool;

    /** @param array<string,mixed> $args validated and normalised against parameters() */
    public function execute(User $user, array $args): ToolResult;
}
