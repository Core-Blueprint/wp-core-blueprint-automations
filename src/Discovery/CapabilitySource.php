<?php
declare(strict_types=1);

namespace CB\Automations\Discovery;

use CB\Automations\Capability\CapabilityReference;

defined( 'ABSPATH' ) || exit;

interface CapabilitySource {
	public function current( CapabilityReference $reference ): ?CapabilityDefinition;

	public function provider_status( string $provider ): ProviderStatus;
}
