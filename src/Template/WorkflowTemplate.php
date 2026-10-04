<?php
declare(strict_types=1);

namespace CB\Automations\Template;

use CB\Automations\Workflow\Definition;

defined( 'ABSPATH' ) || exit;

final readonly class WorkflowTemplate {
	public function __construct(
		private string $provider,
		private string $id,
		private string $version,
		private string $title,
		private string $description,
		private string $category,
		private Definition $definition
	) {}

	public function provider(): string { return $this->provider; }
	public function id(): string { return $this->id; }
	public function version(): string { return $this->version; }
	public function title(): string { return $this->title; }
	public function description(): string { return $this->description; }
	public function category(): string { return $this->category; }
	public function definition(): Definition { return $this->definition; }
	public function key(): string { return $this->provider . '::' . $this->id; }
}
