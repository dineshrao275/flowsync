<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Dto\AiToolCall;
use App\Services\Ai\Dto\AiToolSpec;
use App\Services\TenantLimits;
use App\Support\Hrms\HrmsSchema;
use App\Support\TenantContext;
use App\Support\Tms\TmsSchema;

/**
 * The only door between the model and FlowSync data. A tool is offered to the model, and later
 * executed, only if the tenant's plan has its modules and product AND the caller holds its
 * permission AND the tool says it is available to this user. The same gate runs at execution time,
 * so a tool name the model invents (or one revoked mid-conversation) cannot run.
 */
final class AiToolRegistry
{
    /** @param list<AiTool> $tools */
    public function __construct(
        private readonly array $tools,
        private readonly TenantContext $context,
        private readonly TenantLimits $limits,
    ) {}

    /** @return array<string,AiTool> keyed by tool name */
    public function availableFor(User $user): array
    {
        $tenant = $this->tenant();
        if ($tenant === null) {
            return [];
        }

        $out = [];
        foreach ($this->tools as $tool) {
            if ($this->allowed($tool, $user, $tenant)) {
                $out[$tool->name()] = $tool;
            }
        }

        return $out;
    }

    /** @return list<AiToolSpec> */
    public function specsFor(User $user): array
    {
        return array_values(array_map(
            fn (AiTool $tool) => new AiToolSpec($tool->name(), $tool->description(), $tool->parameters()),
            $this->availableFor($user),
        ));
    }

    /** Never throws: a refusal or bad arguments become an error result the model can read. */
    public function run(User $user, AiToolCall $call): ToolResult
    {
        $tool = $this->availableFor($user)[$call->name] ?? null;
        if ($tool === null) {
            return ToolResult::error("Tool '{$call->name}' is not available.");
        }

        try {
            $args = ArgumentValidator::validate($tool->parameters(), $call->arguments);
        } catch (InvalidToolArguments $e) {
            return ToolResult::error($e->getMessage());
        }

        return $tool->execute($user, $args);
    }

    private function allowed(AiTool $tool, User $user, Tenant $tenant): bool
    {
        $product = $tool->product();
        if ($product !== null && ! $this->productPresent($tenant, $product)) {
            return false;
        }
        foreach ($tool->modules() as $module) {
            if (! $this->limits->hasModule($tenant, $module)) {
                return false;
            }
        }
        if ($tool->permission() !== null && ! $user->hasPermission($tool->permission())) {
            return false;
        }

        return $tool->available($user);
    }

    private function productPresent(Tenant $tenant, string $product): bool
    {
        return $this->limits->productEnabled($tenant, $product)
            && ($product === 'tms' ? TmsSchema::present() : HrmsSchema::present());
    }

    private function tenant(): ?Tenant
    {
        $id = $this->context->currentId();

        return $id === null ? null : Tenant::find($id);
    }
}
