
<!DOCTYPE html>
<html lang="en">
    <head>
        <!-- Required meta tags -->
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

        <link rel="stylesheet" href="<?php echo $stylesheet; ?>">

    </head>
    <body>

        <tr class="container pl-lg-5">
            <div class="row pl-lg-5">
                <h3>
                    <?php echo $module->tt("batch_web_title"); ?>
                </h3>
            </div>
            <div class="row pl-lg-5">
                <h5>
                    <?php echo $module->tt("batch_descrip"); ?>
                </h5>
            </div>

            <form method="post" action="">

                <!-- Display list of records ready for a GC -->
                <input hidden name="action" value="process"/>
                <input hidden name="redcap_csrf_token" value="<?php echo $module->getCSRFToken(); ?>" />
                <?php echo $finalHtml; ?>

                <!-- Form submit button -->
                <div class="row pl-lg-5 pb-5 pt-lg-5">
                    <input class="btn-primary" type="submit" value="<?php echo $module->tt("batch_button"); ?>">
                </div>

            </form>
        </tr>  <!-- END CONTAINER -->

    </body>
</html>

<script>

    function selectAllInConfig(config_name,select_all) {
        $('#'+ config_name + ' input[type="checkbox"]').prop('checked',select_all);
    }

</script>
