{{-- The order's trucks once, its booking number(s), and separate lists only for lines on fewer trucks (req. 17, 23). --}}
<table class="lists">
    <tr>
        <td class="lists-cell">
            <table class="list">
                <thead><tr><th class="sn">S/N</th><th>Trucks</th></tr></thead>
                <tbody>
                    @foreach ($print['allTrucks'] as $i => $reg)
                        <tr><td>{{ $i + 1 }}</td><td>{{ $reg }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </td>
        <td class="lists-cell" style="vertical-align: middle; font-weight: bold;">{{ $print['bookingNumbers'] }}</td>
        @foreach ($print['subsetLines'] as $line)
            <td class="lists-cell">
                <table class="list">
                    <thead><tr><th class="sn">S/N</th><th>{{ $line['description'] }}</th></tr></thead>
                    <tbody>
                        @foreach ($line['trucks'] as $i => $reg)
                            <tr><td>{{ $i + 1 }}</td><td>{{ $reg }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </td>
        @endforeach
    </tr>
</table>
