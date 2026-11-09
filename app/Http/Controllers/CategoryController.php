<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\User;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CategoryController extends Controller
{
  /** Creates a category of the user: `name` is required; `alias` (an emoji), `color` (`#rrggbb`) and `order` are optional. */
  public function store(Request $request) {
    $user = User::find(Auth::user()->id);

    if (!$this->valid($request, $user, null)) {
      return response()->json("error", 400);
    }

    try {
      $category = $user->categories()->create([
        "name" => trim($request->name),
        "alias" => $request->alias,
        "color" => $request->color,
        "order" => $request->order,
      ]);

      if ($request->has("disabled")) {
        $category->disabled = filter_var($request->disabled, FILTER_VALIDATE_BOOLEAN);
        $category->save();
      }
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $category->fresh();
  }

  /**
   * Changes one of the user's categories: only the fields that are sent (`name`, `alias`, `color`, `order`,
   * `disabled`). A category is never deleted: `disabled` hides it from the pickers and stops new expenses from using it.
   */
  public function update(Request $request, $category_id) {
    $user = User::find(Auth::user()->id);
    $category = $user->categories()->find($category_id);

    if (!$category || !$this->valid($request, $user, $category)) {
      return response()->json("error", 400);
    }

    try {
      if ($request->has("name")) {
        $category->name = trim($request->name);
      }

      foreach (["alias", "color", "order"] as $field) {
        if ($request->has($field)) {
          $category->{$field} = $request->input($field);
        }
      }

      if ($request->has("disabled")) {
        $category->disabled = filter_var($request->disabled, FILTER_VALIDATE_BOOLEAN);
      }

      $category->save();
    } catch (Exception $e) {
      return response()->json("error", 400);
    }

    return $category;
  }

  /**
   * `name` (required on create) is a trimmed, non-empty string of up to 50 characters, unique among the user's categories
   * whatever the case; `alias` is `null` or up to 8 characters; `color` is `null` or `#rrggbb`; `order` is `null` or a whole
   * number from 0 to 255; `disabled` a boolean. On update ($current set) only what is sent is checked.
   */
  private function valid(Request $request, User $user, ?Category $current): bool {
    if (!$current || $request->has("name")) {
      $name = $request->name;

      if (!is_string($name) || trim($name) === "" || mb_strlen(trim($name)) > 50) {
        return false;
      }

      $same = $user->categories()->whereRaw("lower(name) = ?", [mb_strtolower(trim($name))]);

      if ($current) {
        $same->where("id", "!=", $current->id);
      }

      if ($same->exists()) {
        return false;
      }
    }

    if ($request->has("alias") && $request->alias !== null && (!is_string($request->alias) || mb_strlen($request->alias) > 8)) {
      return false;
    }

    if ($request->has("color") && $request->color !== null && (!is_string($request->color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $request->color))) {
      return false;
    }

    if ($request->has("order") && $request->order !== null) {
      $order = is_bool($request->order) || is_array($request->order) ? false : filter_var($request->order, FILTER_VALIDATE_INT);

      if ($order === false || $order < 0 || $order > 255) {
        return false;
      }
    }

    if ($request->has("disabled") && filter_var($request->disabled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
      return false;
    }

    return true;
  }
}
