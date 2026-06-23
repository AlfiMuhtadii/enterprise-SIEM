<?php

namespace Tests\Traits;

/**
 * Advisory-only constraint assertions.
 *
 * Use in feature test classes whose corresponding service must NEVER
 * implement execution-level containment or remediation methods.
 * The implementing test class must return its service's FQCN via
 * getAdvisoryServiceClass().
 */
trait AssertsAdvisoryOnlyConstraints
{
    abstract protected function getAdvisoryServiceClass(): string;

    public function test_no_isolate_host_method(): void
    {
        $this->assertFalse(method_exists($this->getAdvisoryServiceClass(), 'isolateHost'));
    }

    public function test_no_quarantine_host_method(): void
    {
        $this->assertFalse(method_exists($this->getAdvisoryServiceClass(), 'quarantineHost'));
    }

    public function test_no_execute_shell_method(): void
    {
        $this->assertFalse(method_exists($this->getAdvisoryServiceClass(), 'executeShell'));
    }

    public function test_no_kill_process_method(): void
    {
        $this->assertFalse(method_exists($this->getAdvisoryServiceClass(), 'killProcess'));
    }

    public function test_no_auto_remediate_method(): void
    {
        $this->assertFalse(method_exists($this->getAdvisoryServiceClass(), 'autoRemediate'));
    }
}
