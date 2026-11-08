<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class CatalogItem extends Model
{
    public $timestamps = false;

    protected $fillable = ['number', 'category', 'level', 'text', 'status', 'author_id', 'couple_id'];

    /** Itens aprovados para todos, mais as sugestões pendentes do próprio autor ou do casal dele. */
    public function scopeVisibleTo(Builder $q, User $user): Builder
    {
        $couple = $user->currentCouple();

        return $q->where(function (Builder $w) use ($user, $couple) {
            $w->where('status', 'approved')
                ->orWhere(function (Builder $p) use ($user, $couple) {
                    $p->where('status', 'pending')->where(function (Builder $o) use ($user, $couple) {
                        $o->where('author_id', $user->id);
                        if ($couple) {
                            $o->orWhere('couple_id', $couple->id);
                        }
                    });
                });
        });
    }
}
