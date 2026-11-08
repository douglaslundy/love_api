<?php

namespace App\Http\Controllers;

use App\Models\CatalogItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LimitController extends Controller
{
    /** Só os limites da própria pessoa. Nunca os do par. */
    public function index(Request $request)
    {
        return response()->json([
            'categories' => CatalogItem::query()->orderBy('number')->pluck('category')->unique()->values(),
            'marked' => DB::table('limits')->where('user_id', $request->user()->id)->pluck('category')->values(),
        ]);
    }

    public function update(Request $request)
    {
        $valid = CatalogItem::query()->pluck('category')->unique()->all();
        $data = $request->validate([
            'categories' => ['present', 'array'],
            'categories.*' => ['string', 'in:'.implode(',', $valid)],
        ]);

        DB::transaction(function () use ($request, $data) {
            DB::table('limits')->where('user_id', $request->user()->id)->delete();
            foreach (array_unique($data['categories']) as $c) {
                DB::table('limits')->insert(['user_id' => $request->user()->id, 'category' => $c]);
            }
        });

        return response()->noContent();
    }
}
