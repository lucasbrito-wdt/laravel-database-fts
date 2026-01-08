<?php

namespace LucasBritoWdt\LaravelDatabaseFts\Traits;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Trait HasTenantScope
 *
 * Fornece isolamento automático por tenant através de Global Scope.
 * Todas as queries são automaticamente filtradas por tenant_id.
 *
 * @package LucasBritoWdt\LaravelDatabaseFts\Traits
 */
trait HasTenantScope
{
    /**
     * Boot the tenant scope.
     *
     * @return void
     */
    protected static function bootHasTenantScope(): void
    {
        if (!\Illuminate\Support\Facades\Config::get('fts.tenancy.enabled', true)) {
            return;
        }

        static::addGlobalScope('tenant', function (Builder $builder) {
            $tenantId = static::getCurrentTenantId();

            if ($tenantId !== null) {
                $column = \Illuminate\Support\Facades\Config::get('fts.tenancy.column', 'tenant_id');
                $table = $builder->getModel()->getTable();
                $builder->where("{$table}.{$column}", $tenantId);
            }
        });
    }

    /**
     * Get the current tenant ID.
     *
     * @return int|string|null
     */
    protected static function getCurrentTenantId()
    {
        // Tenta obter do service container (compatível com Laravel Tenancy, etc)
        if (app()->bound('currentTenant')) {
            $tenant = app('currentTenant');
            return is_object($tenant) && method_exists($tenant, 'id')
                ? $tenant->id()
                : $tenant;
        }

        // Tenta obter do auth (se o usuário tem tenant_id)
        if (auth()->check() && auth()->user()->tenant_id) {
            return auth()->user()->tenant_id;
        }

        // Tenta obter de uma variável de ambiente ou config
        return \Illuminate\Support\Facades\Config::get('fts.tenancy.current_tenant_id');
    }
}
