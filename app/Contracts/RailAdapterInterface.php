<?php

namespace App\Contracts;

interface RailAdapterInterface
{
  /**
   * Execute the action.
   * Returns result array with rail_reference and details.
   * Throws \RuntimeException on failure.
   */
  public function execute(array $config, string $amountNgn): array;

  /**
   * Reverse a completed action using its rollback payload.
   * Called when a later step fails and we need to undo this one.
   */
  public function rollback(array $rollbackPayload): bool;
}
