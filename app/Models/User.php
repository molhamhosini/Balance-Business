<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasTenants;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasTenants
{
    use HasFactory, HasRoles, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'locale', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_active' => 'boolean'];
    }

    public function companies(): BelongsToMany { return $this->belongsToMany(Company::class); }
    public function branches(): BelongsToMany { return $this->belongsToMany(Branch::class); }

    public function canAccessPanel(Panel $panel): bool { return (bool) ($this->is_active ?? true); }

    public function getTenants(Panel $panel): Collection { return $this->companies; }

    public function canAccessTenant(Model $tenant): bool
    {
        return $this->companies()->whereKey($tenant->getKey())->exists();
    }

    /** Branch IDs this user is restricted to within a company; empty = all branches. */
    public function allowedBranchIds(int $companyId): array
    {
        return $this->branches()->where('company_id', $companyId)->pluck('branches.id')->all();
    }

    public function canUseBranch(Branch $branch): bool
    {
        $ids = $this->allowedBranchIds($branch->company_id);

        return $ids === [] || in_array($branch->id, $ids, true);
    }
}
