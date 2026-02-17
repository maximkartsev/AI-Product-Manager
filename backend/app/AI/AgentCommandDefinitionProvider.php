<?php

namespace App\AI;

interface AgentCommandDefinitionProvider
{
    /**
     * Return a machine-readable command definition for AI agents.
     *
     * Required keys:
     * - name
     * - category
     * - purpose
     * - usage
     *
     * Optional keys:
     * - notes (string[])
     */
    public static function getAgentCommandDefinition(): array;
}

