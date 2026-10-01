<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Mcp\Tools\CheckAvailabilityTool;
use App\Mcp\Tools\GetVenueDetailsTool;
use App\Mcp\Tools\RequestQuoteTool;
use App\Mcp\Tools\SearchVenuesTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('MiConvener Marketplace Concierge')]
#[Version('1.0.0')]
#[Instructions('This MCP server empowers AI assistants, agents, and event planners to discover venue spaces across Ghana, inspect technical specs and included/excluded amenities, check availability, and formulate quote requests.')]
final class MarketplaceMcpServer extends Server
{
    /**
     * @var array<int, class-string>
     */
    protected array $tools = [
        SearchVenuesTool::class,
        GetVenueDetailsTool::class,
        CheckAvailabilityTool::class,
        RequestQuoteTool::class,
    ];
}
