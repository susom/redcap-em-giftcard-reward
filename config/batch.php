
<!DOCTYPE html>
<html lang="en">
    <head>
        <!-- Required meta tags -->
        <title>Gift Card Batch Processing</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">
        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous"/>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.0/css/select2.min.css"/>

        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.7.14/css/bootstrap-datetimepicker.min.css">
        <link rel="stylesheet" href="<?php echo $stylesheet; ?>">

        <script src="https://ajax.googleapis.com/ajax/libs/jquery/1.9.1/jquery.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.6-rc.0/js/select2.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/1.12.4/jquery.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.15.1/moment.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/3.3.7/js/bootstrap.min.js"></script>
        <script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datetimepicker/4.7.14/js/bootstrap-datetimepicker.min.js"></script>
    </head>
    <body>

        <tr class="container pl-lg-5">
            <div class="row pl-lg-5">
                <h3>
                    Gift Card Batch Processing Page
                </h3>
            </div>
            <div class="row pl-lg-5">
                <h5>
                    The records below are eligible for Gift Cards. Select the records that you would like to send gift cards to and they will be processed and sent:
                </h5>
            </div>

            <form method="post" action="">

                <!-- Display list of records ready for a GC -->
                <input hidden name="action" value="process"/>
                <?php echo $finalHtml; ?>


                <!-- Form submit button -->
                <div class="row pl-lg-5 pb-5 pt-lg-5">
                    <input class="btn-primary" type="submit" value="Process All Selected Gift Card Records">
                </div>

            </form>
        </div>  <!-- END CONTAINER -->

    </body>
</html>

<script>

    function selectAllInConfig(config_name,select_all) {
        $('#'+ config_name + ' input[type="checkbox"]').prop('checked',select_all);
    }

</script>
