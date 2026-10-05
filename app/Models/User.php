<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Internal accounts are provisioned by managers; verification is optional metadata.
 *
 * @property array<string, mixed>|null $notification_preferences
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    public function setEmailAttribute(mixed $value): void
    {
        $this->attributes['email'] = is_string($value) ? mb_strtolower(trim($value)) : $value;
    }

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'active',
        'role_id',
        'timezone',
        'notification_preferences',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'security_stamp',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
            'notification_preferences' => 'array',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return MorphMany<AuthorizedDatabaseNotification, $this> */
    public function notifications(): MorphMany
    {
        return $this->morphMany(AuthorizedDatabaseNotification::class, 'notifiable')->latest();
    }

    public function hasRole($roleName)
    {
        return $this->role && $this->role->name === $roleName;
    }

    public function hasAnyRole(array $roleNames): bool
    {
        return $this->role && in_array($this->role->name, $roleNames, true);
    }

    public function hasPermission(string $permission): bool
    {
        if (! $this->relationLoaded('role')) {
            $this->load('role.permissions');
        } elseif ($this->role && ! $this->role->relationLoaded('permissions')) {
            $this->role->load('permissions');
        }

        return (bool) $this->role?->permissions->contains('name', $permission);
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assignee_id');
    }

    /** @return HasMany<Project, $this> */
    public function managedProjects(): HasMany
    {
        return $this->hasMany(Project::class, 'project_manager_id');
    }

    /** @return BelongsToMany<Project, $this> */
    public function projects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class)->withTimestamps()->withPivot('added_by');
    }

    /** @return HasMany<Task, $this> */
    public function createdTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'created_by');
    }

    /** @return HasMany<Task, $this> */
    public function assignedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'assigned_by');
    }

    /** @return HasMany<BrowserPushSubscription, $this> */
    public function browserPushSubscriptions(): HasMany
    {
        return $this->hasMany(BrowserPushSubscription::class);
    }

    public function isActive()
    {
        return (bool) $this->active;
    }
}
