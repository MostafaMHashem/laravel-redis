<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBlogRequest;
use App\Http\Requests\UpdateBlogRequest;
use App\Http\Resources\BlogResource;
use App\Http\Resources\CachedBlogResource;
use App\Models\Blog;
use Illuminate\Support\Facades\Redis;

class BlogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $blogs = Blog::all();
        // Try to fetch blogs from Redis list
        $cachedBlogs = Redis::lrange('blog_list', 0, -1); // Get all elements from the list
        
        if (!empty($cachedBlogs)) {
            // Deserialize each blog from JSON
            $blogs = array_map(fn($blog) => json_decode($blog, false), $cachedBlogs);
            return response()->json([
                'status_code' => 200,
                'message' => 'Fetched from Redis',
                'data' => CachedBlogResource::collection($blogs),
            ]);
        }

        // Fetch from database if not in Redis
        $blogs = Blog::all();

        // Clear the existing list to avoid duplicates
        Redis::del('blog_list');

        // Store each blog in the Redis list as JSON
        foreach ($blogs as $blog) {
            Redis::lpush('blog_list', json_encode($blog));
        }

        return response()->json([
            'status_code' => 200,
            'message' => 'Fetched from database',
            'data' => BlogResource::collection($blogs),
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreBlogRequest $request)
    {
        $blog = Blog::create($request->all());

        // Store individual blog as a string for quick lookup
        Redis::set('blog_' . $blog->id, json_encode($blog));

        // Add to the blog list (prepend to maintain order)
        Redis::lpush('blog_list', json_encode($blog));

        return response()->json([
            'status_code' => 201,
            'message' => 'Created',
            'data' => $blog,
        ]);
    }

    /**
     * Display the specified resource.
     */
    public function show(Blog $blog)
    {
        $id = $blog->id;
        $cachedBlog = Redis::get('blog_' . $id);

        if (isset($cachedBlog)) {
            $blog = json_decode($cachedBlog, FALSE);

            return response()->json([
                'status_code' => 201,
                'message' => 'Fetched from redis',
                'data' => $blog,
            ]);
        } else {
            $blog = Blog::find($id);
            // Fetch from database and cache it
            Redis::set('blog_' . $id, json_encode($blog));

            return response()->json([
                'status_code' => 201,
                'message' => 'Fetched from database',
                'data' => $blog,
            ]);
        }
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Blog $blog)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateBlogRequest $request, Blog $blog)
    {
        $update = $blog->update($request->all());

        // Update individual blog in Redis
        Redis::set('blog_' . $blog->id, json_encode($blog));

        // Update the blog in the Redis list
        $blogs = Redis::lrange('blog_list', 0, -1);
        foreach ($blogs as $index => $cachedBlog) {
            $cachedBlogData = json_decode($cachedBlog, true);
            if ($cachedBlogData['id'] == $blog->id) {
                // Use LSET to update the specific index in the list
                Redis::lset('blog_list', $index, json_encode($blog));
                break;
            }
        }

        return response()->json([
            'status_code' => 201,
            'message' => 'User updated',
            'data' => $blog,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Blog $blog)
    {
        Redis::del('blog_' . $blog->id);

        // Remove from the blog list
        $blogs = Redis::lrange('blog_list', 0, -1);
        foreach ($blogs as $index => $cachedBlog) {
            $cachedBlogData = json_decode($cachedBlog, true);
            if ($cachedBlogData['id'] == $blog->id) {
                // Use LREM to remove the specific blog from the list
                Redis::lrem('blog_list', 1, $cachedBlog);
                break;
            }
        }

        $blog->delete();

        return response()->json([
            'status_code' => 201,
            'message' => 'Blog Deleted',
        ]);
    }
}
