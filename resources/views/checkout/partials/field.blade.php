{{--
    落地页表单的通用输入框。

    $name        字段名，同时用作 id / name / 翻译键（checkout.fields.{name}）
    $type        input type，默认 text
    $required    是否必填，默认 true（只影响前端的 required 属性，
                 真正的必填校验一律以 CreateCheckoutOrderRequest 为准）
    $span        是否占满两列
    $autocomplete / $placeholder / $help  可选
--}}
@php
    $type ??= 'text';
    $required ??= true;
    $span ??= false;
    $autocomplete ??= null;
    $placeholder ??= null;
    $help ??= null;
@endphp

<div @class(['sm:col-span-2' => $span])>
    <label for="{{ $name }}" class="block text-sm font-medium">
        {{ __('checkout.fields.'.$name) }}
    </label>
    <input
        type="{{ $type }}"
        id="{{ $name }}"
        name="{{ $name }}"
        value="{{ old($name) }}"
        @if ($required) required @endif
        @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
        @if ($placeholder) placeholder="{{ $placeholder }}" @endif
        @class([
            'mt-1.5 block w-full rounded-lg border px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-1',
            'border-slate-300 focus:border-slate-900 focus:ring-slate-900' => ! $errors->has($name),
            'border-red-400 focus:border-red-500 focus:ring-red-500' => $errors->has($name),
        ])>

    @error($name)
        <p class="mt-1.5 text-xs text-red-600">{{ $message }}</p>
    @else
        @if ($help)
            <p class="mt-1.5 text-xs text-slate-500">{{ $help }}</p>
        @endif
    @enderror
</div>
