jQuery(document).ready(function ($) {
    $('#awcv-compare-button').on('click', function () {
        var version1 = $('#awcv-version-1').val();
        var version2 = $('#awcv-version-2').val();

        if (!window.Diff) {
            $('#awcv-comparison-result').text('Diff library not loaded.');
            return;
        }

        $.ajax({
            url: awcv_ajax.ajax_url,
            type: 'post',
            data: {
                action: 'awcv_compare_versions',
                post_id: awcv_ajax.post_id,
                version_1: version1,
                version_2: version2
            },
            success: function (response) {
                var diff = window.Diff.diffWords(response.content_1, response.content_2);
                var display = diff.map(function (part) {
                    var color = part.added ? 'green' :
                        part.removed ? 'red' : 'grey';
                    return '<span style="color:' + color + ';">' + part.value + '</span>';
                }).join('');
                $('#awcv-comparison-result').html(display);
            }
        });
    });
});
