<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreatePostRequest;
use App\Http\Requests\UpdatePostRequest;
use App\Http\Resources\PostsResource;
use Tymon\JWTAuth\Facades\JWTAuth;
use App\Models\Posts;
use App\QueryFilters\PostsFilter;
use App\Models\Handler;

class PostsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth')->only([
            'index',
            'store',
            'show',
            'update',
            'destroy'
        ]);
        $this->user = JWTAuth::user(JWTAuth::getToken());
    }

    public function index(PostsFilter $filter)
    {
        $posts = Posts::filter($filter)->get();
        if (!Handler::authenticatedAsAdmin($this->user)) {
            $data = $posts->where('user_id', $this->user->id)->where('status', false);
            $data = $data->merge($posts->where('status', true));
            return PostsResource::collection($data);
        } else
            return PostsResource::collection($posts);
    }

    public function store(CreatePostRequest $request)
    {
        try {
            $data = [
                'user_id' => $this->user->id,
                'title' => $request->input('title'),
                'content' => $request->input('content'),
                'category_id' => $request->input('category_id')
            ];

            return Posts::create($data);
        } catch (\Illuminate\Database\QueryException $e) {
            return response([
                'message' => $e->getMessage()
            ]);
        }
    }

    public function show($id)
    {
        if (!Handler::postExists($id)) {
            return response([
                'message' => 'Invalid post'
            ], 404);
        }
        $data = Posts::find($id);

        if (!Handler::authenticatedAsAdmin($this->user)) {
            if ($data->status == false && $data->user_id != $this->user->id)
                return response([
                    'message' => 'You can not view this post!'
                ], 403);
        }

        return $data;
    }

    public function update(UpdatePostRequest $request, $id)
    {
        if (!$data = Posts::find($id))
            return response([
                'message' => 'Invalid post'
            ], 404);

        if (!Handler::authenticatedAsAdmin($this->user)) {
            if ($data->user_id != $this->user->id)
                return response([
                    'message' => 'You can not edit this post!'
                ], 403);
        }

        $data->update($request->only(['title', 'content', 'category_id', 'status']));
        return $data;
    }

    public function destroy($id)
    {
        if (!Handler::postExists($id))
            return response([
                'message' => 'Invalid post'
            ], 404);

        $data = Posts::find($id);
        if (!Handler::authenticatedAsAdmin($this->user)) {
            if ($data->user_id != $this->user->id)
                return response([
                    'message' => 'You can not delete this post!'
                ], 403);
        }

        return Posts::destroy($id);
    }

    public function showPosts($category_id)
    {
        if (!Handler::categoryExists($category_id))
            return response([
                'message' => 'Invalid category!'
            ], 404);

        if (!Handler::authenticatedAsAdmin($this->user)) {
            $data = Posts::whereJsonContains('category_id', (int)$category_id)->get()->where('user_id', $this->user->id)->where('status', false);
            return $data->merge(Posts::whereJsonContains('category_id', (int)$category_id)->get()->where('status', true));
        } else {
            return Posts::whereJsonContains('category_id', (int)$category_id)->get();
        }
    }

    public function addToFaves($post_id)
    {
        if (!$post = Posts::find($post_id))
            return response([
                'message' => 'This post does not exist'
            ], 404);

        if (!Handler::authenticatedAsAdmin($this->user)) {
            if ($post->status == false && $this->user->id != $post->user_id)
                return response([
                    'message' => 'You can not add this post to favorites'
                ], 401);
        }

        $faves = $this->user->faves;
        if ($faves == null) {
            $faves = array();
            array_push($faves, (int)$post_id);
            \App\Models\User::find($this->user->id)->update(['faves' => $faves]);
            return response([
                'message' => 'Post successfully added to favorites'
            ]);
        }

        foreach ($faves as $key) {
            if ($key == $post_id)
                return $this->removeFromFaves($post_id);
        }
        array_push($faves, (int)$post_id);
        \App\Models\User::find($this->user->id)->update(['faves' => $faves]);

        return response([
            'message' => 'Post successfully added to favorites'
        ]);
    }

    public function removeFromFaves($post_id)
    {
        if (!Posts::find($post_id))
            return response([
                'message' => 'This post does not exist'
            ], 404);

        if (!$faves = $this->user->faves)
            return response([
                'message' => 'Nothing to remove'
            ], 404);

        $result = array();
        foreach ($faves as $key) {
            if ($key == (int)$post_id)
                continue;
            array_push($result, (int)$key);
        }
        if ($result == [])
            $result = null;

        \App\Models\User::find($this->user->id)->update(['faves' => $result]);

        return response([
            'message' => 'Post successfully removed from favorites'
        ]);
    }
}
