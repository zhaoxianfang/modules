# 视图使用指南

## 概述

模块系统提供了强大的视图管理功能，支持自动命名空间注册、视图继承、模板扩展等功能。

## 视图目录结构

```
Modules/Blog/Resources/views/
├── layouts/
│   └── app.blade.php
├── post/
│   ├── index.blade.php
│   ├── show.blade.php
│   ├── create.blade.php
│   └── edit.blade.php
├── comment/
│   ├── index.blade.php
│   └── form.blade.php
└── index.blade.php
```

## 视图命名空间

### 自动注册

每个模块会自动注册视图命名空间：

```php
// 配置：'namespace_format' => 'lower'
视图命名空间: blog
视图路径: blog::post.index
```

### 命名格式

在 `config/modules.php` 中配置：

```php
'views' => [
    'namespace_format' => 'lower',  // lower, studly, camel
],
```

| 格式 | 配置值 | 示例 |
|------|--------|------|
| 小写（默认） | `lower` | `blog::post.index` |
| 首字母大写 | `studly` | `Blog::post.index` |
| 驼峰式 | `camel` | `blogModule::post.index` |

## 返回模块视图

### module_view() 函数

```php
// 指定模块
return module_view('Blog', 'post.index', ['posts' => $posts]);

// 使用当前模块（推荐）
return module_view('post.index', compact('posts'));

// 带参数
return module_view('post.show', ['post' => $post]);
```

### Laravel view() 函数

```php
// 使用完整的命名空间
return view('blog::post.index', ['posts' => $posts]);
```

## 视图模板继承

### 创建主布局

创建 `Modules/Blog/Resources/views/layouts/app.blade.php`：

```blade
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Blog')</title>
    <link rel="stylesheet" href="{{ module_asset('css/app.css') }}">
</head>
<body>
    <header>
        <h1>{{ module_config('common.title', 'My Blog') }}</h1>
        <nav>
            <a href="{{ route('blog.home') }}">Home</a>
            <a href="{{ route('blog.posts.index') }}">Posts</a>
        </nav>
    </header>

    <main>
        @yield('content')
    </main>

    <footer>
        <p>&copy; {{ date('Y') }} My Blog</p>
    </footer>

    <script src="{{ module_asset('js/app.js') }}"></script>
</body>
</html>
```

### 继承布局

创建 `Modules/Blog/Resources/views/post/index.blade.php`：

```blade
@extends('layouts.app')

@section('title', 'Posts')

@section('content')
    <div class="posts">
        <h1>Posts</h1>

        @foreach($posts as $post)
            <div class="post">
                <h2>
                    <a href="{{ route('blog.posts.show', $post->id) }}">
                        {{ $post->title }}
                    </a>
                </h2>
                <p>{{ Str::limit($post->content, 200) }}</p>
            </div>
        @endforeach

        {{ $posts->links() }}
    </div>
@endsection
```

## 视图包含

### 基本包含

```blade
{{-- 包含文章卡片 --}}
@include('post.card', ['post' => $post])
```

### 条件包含

```blade
@if($post->featured)
    @include('post.featured', ['post' => $post])
@else
    @include('post.card', ['post' => $post])
@endif
```

### 包含模块视图

```blade
{{-- 包含其他模块的视图 --}}
@include('shared::partials.header')
```

## 视图组件

### 创建组件

创建 `Modules/Blog/Resources/views/components/post-card.blade.php`：

```blade
<div class="post-card">
    <h3>{{ $title }}</h3>
    <p>{{ $slot }}</p>
</div>
```

### 使用组件

```blade
<x-post-card :title="$post->title">
    {{ $post->content }}
</x-post-card>
```

### 组件属性

```blade
<div {{ $attributes }}>
    <!-- 内容 -->
</div>
```

## 视图数据传递

### 从控制器传递

```php
public function show($id)
{
    $post = Post::findOrFail($id);
    $comments = $post->comments()->latest()->get();

    return module_view('post.show', [
        'post' => $post,
        'comments' => $comments,
    ]);
}
```

### 使用 compact()

```php
return module_view('post.show', compact('post', 'comments'));
```

### 在视图中共享数据

在模块服务提供者中：

```php
public function boot()
{
    view()->composer('layouts.app', function ($view) {
        $view->with('siteName', module_config('common.name', 'Blog'));
    });
}
```

## 视图 Helper 函数

### module_view()

```php
// 返回模块视图
return module_view('post.index', ['posts' => $posts]);
```

### module_has_view()

```php
// 检查视图是否存在
if (module_has_view('post.index')) {
    return module_view('post.index');
}

// 检查指定模块的视图
if (module_has_view('Blog', 'post.index')) {
    return view('blog::post.index');
}
```

### module_view_path()

```php
// 获取视图路径（用于返回视图）
$path = module_view_path('post.index');
// 'blog::post.index'
```

### module_views_path()

```php
// 获取视图目录路径
$path = module_views_path();
// '.../Blog/Resources/views'
```

## 视图指令

### 条件指令

```blade
@if(condition)
    <!-- 内容 -->
@elseif(condition)
    <!-- 内容 -->
@else
    <!-- 内容 -->
@endif
```

### 循环指令

```blade
@foreach($items as $item)
    <p>{{ $item }}</p>
@endforeach

@forelse($items as $item)
    <p>{{ $item }}</p>
@empty
    <p>No items</p>
@endforelse

@while(condition)
    <p>{{ $item }}</p>
@endwhile
```

### 认证指令

```blade
@auth
    <!-- 已认证用户 -->
@endauth

@guest
    <!-- 未认证用户 -->
@endguest
```

### CSRF 保护

```blade
<form method="POST" action="{{ route('posts.store') }}">
    @csrf
    <!-- 表单字段 -->
</form>
```

### 方法字段

```blade
<form method="POST" action="{{ route('posts.destroy', $post->id) }}">
    @method('DELETE')
    @csrf
</form>
```

## 视图翻译

### 使用模块翻译

```blade
<h1>{{ module_lang('messages.welcome') }}</h1>

<p>{{ module_lang('messages.greeting', ['name' => $user->name]) }}</p>
```

### 多语言支持

创建 `Modules/Blog/Resources/lang/en/messages.php`：

```php
return [
    'welcome' => 'Welcome to our blog',
    'greeting' => 'Hello, :name!',
];
```

创建 `Modules/Blog/Resources/lang/zh_CN/messages.php`：

```php
return [
    'welcome' => '欢迎来到我们的博客',
    'greeting' => '你好，:name！',
];
```

## 视图最佳实践

### 1. 使用布局继承

```blade
{{-- ✅ 推荐：使用布局 --}}
@extends('layouts.app')

@section('content')
    <!-- 内容 -->
@endsection

{{-- ❌ 不推荐：重复 HTML --}}
<!DOCTYPE html>
<html>
<!-- 重复的内容 -->
</html>
```

### 2. 模块化视图

```blade
{{-- ✅ 推荐：拆分为组件 --}}
@include('post.card')
@include('comment.list')

{{-- ❌ 不推荐：所有代码在一个文件中 --}}
<div class="card"><!-- ... --></div>
<div class="comments"><!-- ... --></div>
```

### 3. 使用命名路由

```blade
{{-- ✅ 推荐：使用命名路由 --}}
<a href="{{ route('blog.posts.show', $post->id) }}">

{{-- ❌ 不推荐：硬编码 URL --}}
<a href="/blog/posts/{{ $post->id }}">
```

### 4. 使用 Helper 函数

```blade
{{-- ✅ 推荐：使用 helper 函数 --}}
<link href="{{ module_asset('css/app.css') }}">
<script src="{{ module_asset('js/app.js') }}"></script>

{{-- ❌ 不推荐：硬编码路径 --}}
<link href="/modules/blog/css/app.css">
```

## 视图缓存

### 缓存视图

```bash
php artisan view:cache
```

### 清除视图缓存

```bash
php artisan view:clear
```

### 在生产环境缓存视图

在 `app/Providers/AppServiceProvider.php` 中：

```php
public function boot()
{
    if (app()->environment('production')) {
        $this->app['view']->composer('*', function ($view) {
            // 预编译视图
        });
    }
}
```

## 相关文档

- [Helper 函数](05-helper-functions.md) - 视图相关的助手函数
- [模块结构](03-module-structure.md) - 视图目录结构
- [路由指南](07-routes.md) - 视图与路由的结合使用
