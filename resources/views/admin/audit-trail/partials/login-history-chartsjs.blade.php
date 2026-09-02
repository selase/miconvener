    <script>
        var browserSession = document.getElementById('browser-session');
            var browserSessionChart = echarts.init(browserSession);
            var option;

            option = {
                tooltip: {
                    trigger: 'item'
                },
                legend: {
                    top: '5%',
                    left: 'center'
                },
                series: [
                    {
                    name: 'Browser',
                    type: 'pie',
                    radius: ['40%', '70%'],
                    avoidLabelOverlap: false,
                    itemStyle: {
                        borderRadius: 10,
                        borderColor: '#fff',
                        borderWidth: 2
                    },
                    label: {
                        show: false,
                        position: 'center'
                    },
                    emphasis: {
                        label: {
                        show: true,
                        fontSize: '40',
                        fontWeight: 'bold'
                        }
                    },
                    labelLine: {
                        show: false
                    },
                    data: [
                        @foreach ($loggedInBrowserChartForCurrentMonth as $key => $value)
                            { value: '{{ $value }}', name: '{{ $key }}' },
                        @endforeach
                    ]
                    }
                ]
            };

            option && browserSessionChart.setOption(option);


            var locationSession = document.getElementById('location-session');
            var locationSessionChart = echarts.init(locationSession);
            var option;

            option = {
                tooltip: {
                    trigger: 'item'
                },
                legend: {
                    top: '5%',
                    left: 'center'
                },
                series: [
                    {
                    name: 'Locations',
                    type: 'pie',
                    radius: ['40%', '70%'],
                    avoidLabelOverlap: false,
                    itemStyle: {
                        borderRadius: 10,
                        borderColor: '#fff',
                        borderWidth: 2
                    },
                    label: {
                        show: false,
                        position: 'center'
                    },
                    emphasis: {
                        label: {
                        show: true,
                        fontSize: '40',
                        fontWeight: 'bold'
                        }
                    },
                    labelLine: {
                        show: false
                    },
                    data: [
                        @foreach ($loggedInLocationChartForCurrentMonth as $key => $value)
                            { value: '{{ $value }}', name: '{{ $key }}' },
                        @endforeach
                    ]
                    }
                ]
            };

            option && locationSessionChart.setOption(option);


            var platformSession = document.getElementById('platform-session');
            var platformSessionChart = echarts.init(platformSession);
            var option;

            option = {
                tooltip: {
                    trigger: 'item'
                },
                legend: {
                    top: '5%',
                    left: 'center'
                },
                series: [
                    {
                    name: 'Platform/OS',
                    type: 'pie',
                    radius: ['40%', '70%'],
                    avoidLabelOverlap: false,
                    itemStyle: {
                        borderRadius: 10,
                        borderColor: '#fff',
                        borderWidth: 2
                    },
                    label: {
                        show: false,
                        position: 'center'
                    },
                    emphasis: {
                        label: {
                        show: true,
                        fontSize: '40',
                        fontWeight: 'bold'
                        }
                    },
                    labelLine: {
                        show: false
                    },
                    data: [
                        @foreach ($loggedInPlatformChartForCurrentMonth as $key => $value)
                            { value: '{{ $value }}', name: '{{ $key }}' },
                        @endforeach
                    ]
                    }
                ]
            };

            option && platformSessionChart.setOption(option);


            var clientDeviceSession = document.getElementById('client-device-session');
            var clientDeviceChart = echarts.init(clientDeviceSession);
            var option;

            option = {
                tooltip: {
                    trigger: 'item'
                },
                legend: {
                    top: '5%',
                    left: 'center'
                },
                series: [
                    {
                    name: 'Devices',
                    type: 'pie',
                    radius: ['40%', '70%'],
                    avoidLabelOverlap: false,
                    itemStyle: {
                        borderRadius: 10,
                        borderColor: '#fff',
                        borderWidth: 2
                    },
                    label: {
                        show: false,
                        position: 'center'
                    },
                    emphasis: {
                        label: {
                        show: true,
                        fontSize: '40',
                        fontWeight: 'bold'
                        }
                    },
                    labelLine: {
                        show: false
                    },
                    data: [
                        @foreach ($loggedInClientDeviceChartForCurrentMonth as $key => $value)
                            { value: '{{ $value }}', name: '{{ strtoupper($key) }}' },
                        @endforeach
                    ]
                    }
                ]
            };

        option && clientDeviceChart.setOption(option);
    </script>