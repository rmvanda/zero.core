<?php

namespace Zero\Tests\Core;

use Zero\Core\GroupPermission;

class GroupPermissionTest extends ZeroTestCase
{
    private function createGroupPermission(?\PDO $pdo = null): GroupPermission
    {
        return new GroupPermission(
            $pdo ?? $this->createMockPdo(),
            'lists',
            'list_id',
            'list_permissions',
            'list'
        );
    }

    /* ── Constructor ── */

    public function testCanBeInstantiated(): void
    {
        $gp = $this->createGroupPermission();
        $this->assertInstanceOf(GroupPermission::class, $gp);
    }

    /* ── grant() ── */

    public function testGrantReturnsTrue(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
             ->method('execute')
             ->with([1, 10, 1, 0, 0])
             ->willReturn(true);

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $gp = $this->createGroupPermission($pdo);
        $this->assertTrue($gp->grant(1, 10));
    }

    public function testGrantWithAllPermissions(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
             ->method('execute')
             ->with([1, 10, 1, 1, 1])
             ->willReturn(true);

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $gp = $this->createGroupPermission($pdo);
        $this->assertTrue($gp->grant(1, 10, true, true, true));
    }

    public function testGrantThrowsOnPdoException(): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('execute')
             ->willThrowException(new \PDOException('DB error'));

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $gp = $this->createGroupPermission($pdo);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to grant/');
        $gp->grant(1, 10);
    }

    /* ── revoke() ── */

    public function testRevokeReturnsTrue(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
             ->method('execute')
             ->with([1, 10])
             ->willReturn(true);

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $gp = $this->createGroupPermission($pdo);
        $this->assertTrue($gp->revoke(1, 10));
    }

    /* ── update() ── */

    public function testUpdateSinglePermission(): void
    {
        $stmt = $this->createMock(\PDOStatement::class);
        $stmt->expects($this->once())
             ->method('execute')
             ->willReturn(true);

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $gp = $this->createGroupPermission($pdo);
        $this->assertTrue($gp->update(1, 10, ['can_update' => true]));
    }

    public function testUpdateThrowsWhenNoPermissionsProvided(): void
    {
        $gp = $this->createGroupPermission();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No permissions to update');
        $gp->update(1, 10, []);
    }

    /* ── hasPermission() ── */

    public function testOwnerAlwaysHasPermission(): void
    {
        // First query: entity lookup returns user_id = 5
        $stmtEntity = $this->createStub(\PDOStatement::class);
        $stmtEntity->method('execute')->willReturn(true);
        $stmtEntity->method('fetch')->willReturn(['user_id' => 5]);

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmtEntity);

        $gp = $this->createGroupPermission($pdo);

        // User 5 is the owner — should have all permission types
        $this->assertTrue($gp->hasPermission(1, 5, 'read'));
        $this->assertTrue($gp->hasPermission(1, 5, 'update'));
        $this->assertTrue($gp->hasPermission(1, 5, 'delete'));
    }

    public function testNonOwnerChecksGroupPermission(): void
    {
        // Build two statements: entity lookup and permission check
        $callCount = 0;

        $stmtEntity = $this->createStub(\PDOStatement::class);
        $stmtEntity->method('execute')->willReturn(true);
        $stmtEntity->method('fetch')->willReturn(['user_id' => 5]);

        $stmtPerm = $this->createStub(\PDOStatement::class);
        $stmtPerm->method('execute')->willReturn(true);
        $stmtPerm->method('fetch')->willReturn(['count' => 1]);

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callCount, $stmtEntity, $stmtPerm) {
                $callCount++;
                return $callCount <= 1 ? $stmtEntity : $stmtPerm;
            });

        $gp = $this->createGroupPermission($pdo);

        // User 99 is NOT the owner — should check group permissions
        $this->assertTrue($gp->hasPermission(1, 99, 'read'));
    }

    public function testReturnsFalseWhenEntityNotFound(): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('execute')->willReturn(true);
        $stmt->method('fetch')->willReturn(false);

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $gp = $this->createGroupPermission($pdo);
        $this->assertFalse($gp->hasPermission(999, 1, 'read'));
    }

    /* ── getByEntity() ── */

    public function testGetByEntityReturnsResults(): void
    {
        $rows = [
            ['group_id' => 1, 'group_name' => 'Team A', 'can_read' => 1],
            ['group_id' => 2, 'group_name' => 'Team B', 'can_read' => 1],
        ];

        $pdo = $this->createMockPdo(fetchAllReturn: $rows);

        $gp = $this->createGroupPermission($pdo);
        $result = $gp->getByEntity(1);

        $this->assertCount(2, $result);
        $this->assertSame('Team A', $result[0]['group_name']);
    }

    public function testGetByEntityReturnsEmptyOnError(): void
    {
        $stmt = $this->createStub(\PDOStatement::class);
        $stmt->method('execute')
             ->willThrowException(new \PDOException('fail'));

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')->willReturn($stmt);

        $gp = $this->createGroupPermission($pdo);
        $this->assertSame([], $gp->getByEntity(1));
    }

    /* ── find() ── */

    public function testFindReturnsPermission(): void
    {
        $row = ['group_id' => 10, 'group_name' => 'Admins', 'can_read' => 1, 'can_update' => 1];
        $pdo = $this->createMockPdo(fetchReturn: $row);

        $gp = $this->createGroupPermission($pdo);
        $result = $gp->find(1, 10);

        $this->assertSame('Admins', $result['group_name']);
    }

    public function testFindReturnsNullWhenNotFound(): void
    {
        $pdo = $this->createMockPdo(fetchReturn: false);

        $gp = $this->createGroupPermission($pdo);
        $this->assertNull($gp->find(1, 999));
    }

    /* ── batchGrant() ── */

    public function testBatchGrantTracksSuccessAndFailure(): void
    {
        $callCount = 0;

        $stmtOk = $this->createStub(\PDOStatement::class);
        $stmtOk->method('execute')->willReturn(true);

        $stmtFail = $this->createStub(\PDOStatement::class);
        $stmtFail->method('execute')
                 ->willThrowException(new \PDOException('fail'));

        $pdo = $this->createStub(\PDO::class);
        $pdo->method('prepare')
            ->willReturnCallback(function () use (&$callCount, $stmtOk, $stmtFail) {
                $callCount++;
                return $callCount <= 1 ? $stmtOk : $stmtFail;
            });

        $gp = $this->createGroupPermission($pdo);
        $results = $gp->batchGrant(1, [
            10 => ['can_read' => true],
            20 => ['can_read' => true],
        ]);

        $this->assertCount(1, $results['success']);
        $this->assertCount(1, $results['failed']);
        $this->assertSame(10, $results['success'][0]);
    }
}
