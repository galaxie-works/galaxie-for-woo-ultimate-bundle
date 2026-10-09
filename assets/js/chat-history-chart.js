/**
 * AI Chat for WordPress - Chat History Chart
 *
 * Initializes and renders the chat history activity chart
 * using Chart.js library. Supports period switching (month/year).
 *
 * @package AICWP
 * @since 1.7.5
 */

(function($) {
    'use strict';

    var chatHistoryChart = null;
    var currentPeriod = 'month';

    function buildDatasets(data) {
        var datasets = [
            {
                label: data.strings.conversations,
                data: data.conversations,
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.3,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#3b82f6',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2
            },
            {
                label: data.strings.messages,
                data: data.messages,
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.3,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2
            }
        ];

        if (data.showEmails && data.emails) {
            datasets.push({
                label: data.strings.emails,
                data: data.emails,
                borderColor: '#8b5cf6',
                backgroundColor: 'rgba(139, 92, 246, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.3,
                pointRadius: 3,
                pointHoverRadius: 5,
                pointBackgroundColor: '#8b5cf6',
                pointBorderColor: '#ffffff',
                pointBorderWidth: 2
            });
        }

        return datasets;
    }

    function createChart(chartData) {
        var canvas = document.getElementById('aicwp-ui-chat-history-chart');
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }

        var ctx = canvas.getContext('2d');

        var chartConfig = {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: buildDatasets(chartData)
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(0, 0, 0, 0.8)',
                        titleColor: '#ffffff',
                        bodyColor: '#ffffff',
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: true,
                        boxWidth: 12,
                        boxHeight: 12,
                        boxPadding: 4,
                        callbacks: {
                            title: function(context) {
                                return context[0].label;
                            },
                            label: function(context) {
                                var label = context.dataset.label || '';
                                var value = context.parsed.y;
                                return ' ' + label + ': ' + value;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: {
                            display: false
                        },
                        ticks: {
                            color: '#6b7280',
                            font: {
                                size: 11
                            },
                            maxRotation: 45,
                            minRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: 15
                        },
                        border: {
                            display: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.05)',
                            drawBorder: false
                        },
                        ticks: {
                            color: '#6b7280',
                            font: {
                                size: 11
                            },
                            stepSize: 1,
                            callback: function(value) {
                                if (Number.isInteger(value)) {
                                    return value;
                                }
                                return null;
                            }
                        },
                        border: {
                            display: false
                        }
                    }
                }
            }
        };

        try {
            if (chatHistoryChart) {
                chatHistoryChart.destroy();
            }
            chatHistoryChart = new Chart(ctx, chartConfig);
        } catch (error) {
            console.error('Chat History Chart initialization failed:', error);
        }
    }

    function updateChartWithResponse(response) {
        if (!response.success || !response.data) {
            return;
        }

        var d = response.data;
        var data = $.extend({}, aiChatHistoryChartData, {
            labels: d.labels,
            conversations: d.conversations,
            messages: d.messages,
            emails: d.emails,
            showEmails: d.showEmails
        });

        createChart(data);
        updateLegend(data);
    }

    function updateLegend(data) {
        var $container = $('.aicwp-ui-chart-toolbar');
        if (!$container.length) {
            return;
        }

        var $legend = $container.find('.aicwp-ui-chart-legend').empty();

        var items = [
            { cls: 'aicwp-ui-legend-conversations', text: data.strings.conversations },
            { cls: 'aicwp-ui-legend-messages', text: data.strings.messages }
        ];
        if (data.showEmails) {
            items.push({ cls: 'aicwp-ui-legend-emails', text: data.strings.emails });
        }

        $.each(items, function(_, item) {
            $('<span>').addClass('aicwp-ui-legend-item ' + item.cls)
                .append($('<span>').addClass('aicwp-ui-legend-color'))
                .append($('<span>').text(item.text))
                .appendTo($legend);
        });
    }

    function switchPeriod(period) {
        if (period === currentPeriod) {
            return;
        }
        currentPeriod = period;

        $.ajax({
            url: aiChatHistoryChartData.ajaxUrl,
            type: 'POST',
            data: {
                action: 'aicwp_get_chart_data',
                nonce: aiChatHistoryChartData.nonce,
                period: period
            },
            success: function(response) {
                updateChartWithResponse(response);
            },
            error: function() {
                // Revert button state on failure
                $('.aicwp-ui-chart-period-toggle .aicwp-ui-position-btn').removeClass('active');
                $('.aicwp-ui-chart-period-toggle .aicwp-ui-position-btn[data-period="' + (period === 'month' ? 'year' : 'month') + '"]').addClass('active');
                currentPeriod = period === 'month' ? 'year' : 'month';
            }
        });
    }

    function initChatHistoryChart() {
        var canvas = document.getElementById('aicwp-ui-chat-history-chart');
        if (!canvas) {
            return;
        }

        if (typeof Chart === 'undefined') {
            console.log('AI Chat: Chart.js not loaded');
            return;
        }

        if (typeof aiChatHistoryChartData === 'undefined') {
            console.log('AI Chat: Chart data not available');
            return;
        }

        createChart(aiChatHistoryChartData);
    }

    function debounce(func, wait) {
        var timeout;
        return function() {
            var context = this, args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(function() {
                func.apply(context, args);
            }, wait);
        };
    }

    var handleResize = debounce(function() {
        if (chatHistoryChart) {
            chatHistoryChart.resize();
        }
    }, 250);

    $(document).ready(function() {
        initChatHistoryChart();

        // Period toggle buttons
        $(document).on('click', '.aicwp-ui-chart-period-toggle .aicwp-ui-position-btn', function() {
            var period = $(this).data('period');
            if (period === currentPeriod) {
                return;
            }

            $(this).siblings().removeClass('active');
            $(this).addClass('active');
            switchPeriod(period);
        });

        $(window).on('resize', handleResize);
    });

})(jQuery);
