<?php

declare(strict_types=1);

namespace MiniErp\Services;

use DomainException;
use MiniErp\Context\AuthenticatedPlatformAdmin;
use MiniErp\Contracts\ControlPlaneReaderContract;
use MiniErp\Contracts\PlatformAdminAuthorizerContract;

final class ControlPlaneBootstrapService
{
    public function __construct(
        private ControlPlaneReaderContract $reader,
        private PlatformAdminAuthorizerContract $authorizer
    ) {
    }

    public function resolveIdentity(?int $authenticatedUserId): AuthenticatedPlatformAdmin
    {
        if ($authenticatedUserId === null || $authenticatedUserId < 1) {
            throw new DomainException('Authentication is required.');
        }

        $identity = $this->reader->findActiveIdentityByUserId($authenticatedUserId);
        if (!$identity instanceof AuthenticatedPlatformAdmin) {
            throw new DomainException('Authenticated identity is unavailable or inactive.');
        }

        if (!$this->authorizer->isAuthorized($identity)) {
            throw new DomainException('Control-plane access is not authorized.');
        }

        return $identity;
    }

    /** @return array<int, array<string, mixed>> */
    public function listTenants(AuthenticatedPlatformAdmin $identity): array
    {
        if (!$this->authorizer->isAuthorized($identity)) {
            throw new DomainException('Control-plane access is not authorized.');
        }

        return array_values(array_filter($this->reader->listTenants(), static fn(array $tenant): bool => \MiniErp\Authorization\PlatformDatabaseAccess::canView($identity, $tenant)));
    }

    public function searchTenants(AuthenticatedPlatformAdmin $identity,string $query,int $page,int $limit,string $status=''):array
    {if(!$this->authorizer->isAuthorized($identity))throw new DomainException('Control-plane access is not authorized.');if(!method_exists($this->reader,'searchTenants'))return ['items'=>$this->listTenants($identity),'total'=>count($this->listTenants($identity)),'page'=>1,'limit'=>100];return $this->reader->searchTenants($query,$page,$limit,$status,$identity);}
}
