{{-- 订单商品明细表格的底部汇总行（通过 Table::contentFooter() 渲染）。
     注意：该视图会被放进 <tfoot><tr> 里，且 Filament 会对其调用 ->with()
     注入 columns / records，所以这里必须是 Blade 视图，不能是 HtmlString；
     根节点需要是 <td>，colspan 横跨全部列。 --}}
<td
    colspan="{{ count($columns) }}"
    class="fi-ta-order-items-summary px-3 py-2.5"
>
    <div class="flex flex-wrap items-center gap-x-6 gap-y-1 text-sm text-gray-950 dark:text-white">
        @foreach ($summary as $label => $value)
            <span>
                &nbsp;&nbsp;&nbsp;
                {{ $label }}:
                <span class="font-semibold"> {{ $value }}</span>
            </span>
        @endforeach
    </div>
</td>
