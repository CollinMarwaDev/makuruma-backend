{{-- Truck lists in rows of four, so long lists or many lines never run into each other (UAT 20 Nov). Expects $lists. --}}
@foreach (collect($lists)->chunk(4) as $chunk)
    <table class="lists">
        <tr>
            @foreach ($chunk as $list)
                <td class="lists-cell">@include('expenses.partials.vibali-truck-list', ['list' => $list])</td>
            @endforeach
            @for ($i = $chunk->count(); $i < 4; $i++)
                <td class="lists-cell"></td>
            @endfor
        </tr>
    </table>
@endforeach
