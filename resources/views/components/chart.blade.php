@props(['id' => 'chart-' . uniqid(), 'type' => 'line', 'data' => [], 'options' => []])

<div class="relative w-full h-64">
    <canvas id="{{ $id }}" x-data="{
                chart: null,
                init() {
                    const ctx = document.getElementById('{{ $id }}').getContext('2d');
                    this.chart = new Chart(ctx, {
                        type: '{{ $type }}',
                        data: {{ json_encode($data) }},
                        options: {{ json_encode($options) }}
                    });
                }
            }" x-init="init()"></canvas>
</div>