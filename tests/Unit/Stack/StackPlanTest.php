<?php

declare(strict_types=1);

namespace App\Tests\Unit\Stack;

use App\Stack\InvalidStackRequest;
use App\Stack\ServiceCondition;
use App\Stack\StackPlan;
use App\Stack\StackRequest;
use PHPUnit\Framework\TestCase;

/**
 * Ordering, resolved before anything is created.
 *
 * The value of these tests is the "before anything is created" half: each
 * refusal here happens while the stack is still a value, so an unknown
 * dependency or a cycle can never be discovered halfway through a bring-up.
 *
 * @internal
 */
final class StackPlanTest extends TestCase
{
    public function test_services_come_out_after_everything_they_depend_on(): void
    {
        $plan = StackPlan::for($this->up([
            'web' => ['image' => 'nginx', 'depends_on' => ['db', 'cache']],
            'db' => ['image' => 'postgres:17-alpine'],
            'cache' => ['image' => 'redis:7-alpine'],
        ]));

        $order = array_map(static fn (array $step): string => $step[0]->name, $plan->steps);

        self::assertSame(['db', 'cache', 'web'], $order, 'dependencies first, and the request order kept among equals');
    }

    public function test_the_order_is_stable_for_the_same_request(): void
    {
        $services = [
            'branding' => ['image' => 'nginx'],
            'api' => ['image' => 'nginx'],
            'migrate' => ['image' => 'nginx'],
            'web' => ['image' => 'nginx', 'depends_on' => ['api']],
        ];

        $first = array_map(static fn (array $step): string => $step[0]->name, StackPlan::for($this->up($services))->steps);
        $second = array_map(static fn (array $step): string => $step[0]->name, StackPlan::for($this->up($services))->steps);

        self::assertSame($first, $second, 'two resolves of the same request produce the same sequence');
    }

    public function test_a_dependency_on_a_service_not_in_the_request_is_refused(): void
    {
        try {
            StackPlan::for($this->up([
                'web' => ['image' => 'nginx', 'depends_on' => ['db']],
            ]));
            self::fail('an unknown dependency must be refused');
        } catch (InvalidStackRequest $e) {
            self::assertStringContainsString('not a service in this stack', $e->getMessage());
            self::assertStringContainsString('resolved against this request only', $e->getMessage());
        }
    }

    /**
     * The cross-stack version of the same rule: a dependency names a *service
     * of this stack*, and there is no shape in which it could name a container
     * — so pointing it at a neighbour is not "validated away", it is
     * unexpressible.
     */
    public function test_a_dependency_cannot_name_a_container_or_a_neighbour(): void
    {
        try {
            StackPlan::for($this->up([
                'web' => ['image' => 'nginx', 'depends_on' => ['lyra-terminal']],
            ]));
            self::fail('a dependency outside this stack must be refused');
        } catch (InvalidStackRequest $e) {
            self::assertStringContainsString('"lyra-terminal"', $e->getMessage());
        }
    }

    public function test_a_cycle_is_refused_and_named(): void
    {
        try {
            StackPlan::for($this->up([
                'a' => ['image' => 'nginx', 'depends_on' => ['b']],
                'b' => ['image' => 'nginx', 'depends_on' => ['c']],
                'c' => ['image' => 'nginx', 'depends_on' => ['a']],
            ]));
            self::fail('a cycle must be refused');
        } catch (InvalidStackRequest $e) {
            self::assertStringContainsString('cycle among: a, b, c', $e->getMessage());
        }
    }

    public function test_healthy_on_a_service_with_no_health_command_is_refused_with_the_way_forward(): void
    {
        try {
            StackPlan::for($this->up([
                'web' => ['image' => 'nginx', 'depends_on' => ['db' => ['condition' => 'healthy']]],
                'db' => ['image' => 'postgres:17-alpine'],
            ]));
            self::fail('healthy without a health command can never be true, so it must be refused up front');
        } catch (InvalidStackRequest $e) {
            self::assertStringContainsString('"db" declares no health_cmd', $e->getMessage());
            self::assertStringContainsString('"started" condition', $e->getMessage());
        }
    }

    public function test_a_self_dependency_is_refused(): void
    {
        try {
            StackPlan::for($this->up([
                'web' => ['image' => 'nginx', 'depends_on' => ['web']],
            ]));
            self::fail('a self-dependency must be refused');
        } catch (InvalidStackRequest $e) {
            self::assertStringContainsString('depends on itself', $e->getMessage());
        }
    }

    public function test_completed_and_healthy_both_resolve_when_the_mechanism_exists(): void
    {
        $plan = StackPlan::for($this->up([
            'web' => ['image' => 'nginx', 'depends_on' => ['migrate' => ['condition' => 'completed'], 'db' => ['condition' => 'healthy']]],
            'migrate' => ['image' => 'nginx'],
            'db' => ['image' => 'postgres:17-alpine', 'health_cmd' => ['pg_isready']],
        ]));

        $web = null;

        foreach ($plan->steps as [$service, $dependencies]) {
            if ('web' === $service->name) {
                $web = $dependencies;
            }
        }

        self::assertNotNull($web);
        self::assertCount(2, $web);
        $conditions = array_map(static fn (array $pair): ServiceCondition => $pair[1], $web);
        self::assertContains(ServiceCondition::Completed, $conditions);
        self::assertContains(ServiceCondition::Healthy, $conditions);
    }

    /**
     * @param array<string, array<string, mixed>> $services
     */
    private function up(array $services): StackRequest
    {
        return StackRequest::fromArray(['action' => 'up', 'services' => $services]);
    }
}
