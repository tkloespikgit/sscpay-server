{{--
    争议审核事件图片凭证：缩略图网格 + 点击放大的灯箱（含下载）。

    样式一律写成 inline style 而不是 Tailwind 类名——本项目没有自定义
    Filament 主题（不跑 Tailwind 构建），新写的类名不会被编译进样式表。
--}}
@php
    $images = \App\Support\DisputeAttachments::for($getRecord());
@endphp

@if (filled($images))
    <div
        x-data="{
            images: @js($images),
            current: null,
            show(index) { this.current = index },
            close() { this.current = null },
            prev() { this.current = (this.current + this.images.length - 1) % this.images.length },
            next() { this.current = (this.current + 1) % this.images.length },
        }"
        x-on:keydown.escape.window="close()"
        x-on:keydown.arrow-left.window="current !== null && prev()"
        x-on:keydown.arrow-right.window="current !== null && next()"
    >
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem;">
            <template x-for="(image, index) in images" :key="index">
                <button
                    type="button"
                    x-on:click="show(index)"
                    :title="@js(__('admin.order_dispute_event.images_viewer.open'))"
                    style="padding: 0; border: 0; background: none; cursor: zoom-in; line-height: 0;"
                >
                    <img
                        :src="image.url"
                        :alt="image.name"
                        loading="lazy"
                        style="height: 7rem; width: 7rem; object-fit: cover; border-radius: 0.5rem; box-shadow: 0 0 0 1px rgba(0, 0, 0, 0.1);"
                    />
                </button>
            </template>
        </div>

        {{-- 传送到 body：不然灯箱会被表格/卡片的 overflow 和层叠上下文裁掉 --}}
        <template x-teleport="body">
            <div
                x-show="current !== null"
                x-cloak
                x-on:click.self="close()"
                style="position: fixed; inset: 0; z-index: 50; background: rgba(0, 0, 0, 0.9); display: flex; flex-direction: column;"
            >
                <div
                    style="display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: 0.75rem 1rem; color: #fff;"
                >
                    <span style="font-size: 0.875rem; opacity: 0.8;" x-text="`${current + 1} / ${images.length}`"></span>

                    <span style="display: flex; gap: 0.5rem;">
                        <a
                            :href="images[current]?.download"
                            style="padding: 0.375rem 0.75rem; border-radius: 0.5rem; background: rgba(255, 255, 255, 0.15); color: #fff; font-size: 0.875rem; text-decoration: none;"
                        >{{ __('admin.order_dispute_event.images_viewer.download') }}</a>

                        <button
                            type="button"
                            x-on:click="close()"
                            style="padding: 0.375rem 0.75rem; border: 0; border-radius: 0.5rem; background: rgba(255, 255, 255, 0.15); color: #fff; font-size: 0.875rem; cursor: pointer;"
                        >{{ __('admin.order_dispute_event.images_viewer.close') }}</button>
                    </span>
                </div>

                <div
                    x-on:click.self="close()"
                    style="flex: 1; display: flex; align-items: center; justify-content: center; gap: 1rem; padding: 0 1rem 1.5rem; min-height: 0;"
                >
                    <button
                        type="button"
                        x-show="images.length > 1"
                        x-on:click="prev()"
                        :aria-label="@js(__('admin.order_dispute_event.images_viewer.previous'))"
                        style="flex: none; width: 2.5rem; height: 2.5rem; border: 0; border-radius: 9999px; background: rgba(255, 255, 255, 0.15); color: #fff; font-size: 1.25rem; cursor: pointer;"
                    >&lsaquo;</button>

                    <img
                        :src="images[current]?.url"
                        :alt="images[current]?.name"
                        style="max-width: 100%; max-height: 100%; object-fit: contain;"
                    />

                    <button
                        type="button"
                        x-show="images.length > 1"
                        x-on:click="next()"
                        :aria-label="@js(__('admin.order_dispute_event.images_viewer.next'))"
                        style="flex: none; width: 2.5rem; height: 2.5rem; border: 0; border-radius: 9999px; background: rgba(255, 255, 255, 0.15); color: #fff; font-size: 1.25rem; cursor: pointer;"
                    >&rsaquo;</button>
                </div>
            </div>
        </template>
    </div>
@else
    <span style="color: rgb(113 113 122);">{{ __('admin.order_dispute_event.placeholders.none') }}</span>
@endif
