// Create a javascript object to hold all of our config features
GiftCardRewardConfig = {

    init: function(params) {
        // Make true
        this.isDev = true;

        // We need to delay a little bit for the rest of the config to finish loading
        setTimeout(function() {
            GiftCardRewardConfig.configAjax.call(GiftCardRewardConfig,'config/ajax')
        }, 200);
    },


    configAjax: function(page) {

        this.configureModal = $('#external-modules-configure-modal');
        this.url = this.getAjaxUrl(page);
        this.createStatusDiv('config_status');

        // Add an event handler so on any change to the form, we update the status
        this.configureModal.on('change', 'input, select, textarea', GiftCardRewardConfig.getStatus.bind(this));

        // Add event handler in case we return buttons after a status update
        this.statusDiv.on('click', '.btn', GiftCardRewardConfig.doAction.bind(this));

        // Let's call getStatus once to start us off:
        this.getStatus();

        // $('tr.sub_start td:not([class]), tr.sub_start td[class=""]').each(function(i,j) {
        //     nextTd = $(j).next('td');
        //     $(j).remove();
        //     nextTd.attr('colspan','2');
        // });

    },


    getAjaxUrl: function(page) {
        var moduleDirectoryPrefix = this.configureModal.data('module');
        return app_path_webroot + "ExternalModules/?prefix=" + moduleDirectoryPrefix + "&page=" + encodeURIComponent(page) + "&pid=" + pid;
    },


    createStatusDiv: function(id) {
        this.statusDiv = $('<div></div>')
            .attr('id', id);
        this.statusDiv
            .wrap( $('<td>', { id: 'config-th', colspan: '3' }) )
            .parent()
            .wrap('<tr>')
            .parent()
            .prependTo('.modal-body tbody');
    },


    getStatus: function () {
        // Assemble data from modal form
        var raw = this.getRawForm();
        var data = {
            'action'    : 'getStatus',
            'raw'       : raw
        };
        //console.log("GET STATUS", data, this.url);

        $.ajax({
            method: "POST",
            url: this.url,
            data: data,
            dataType: "json"
        })
            .done(function (data) {
                // Data is a json array of:
                // {
                //   "result"   : false,
                //   "messages" : ["<b>Configuration Issues with #1 'Title Goes <b>HERE<\/b>'<\/b><ul><li>Field asdf is not found in project<\/li><\/ul>"],
                //   "callback" : null,
                //   "delay"    : null
                // }
                //console.log("Ajax Done", data);

                var configStatus = $('#config_status');
                configStatus.html('');
                var cls = data.result ? 'alert-success': 'alert-danger';
                $.each(data.message, function (i, alert) {
                    $('<div></div>')
                        .addClass('alert')
                        .addClass(cls)
                        .html(alert)
                        .appendTo(configStatus);
                });
            })
            .fail(function () {
            })
            .always(function() {
            });
    },


    getRawForm: function() {
        var data = {};
        var inputs = $('#external-modules-configure-modal').find('input, select, textarea');

        //this.log(inputs.each(function(i,e){ this.log(i, $(e).attr('name')); }));

        inputs.each(function(index, element) {

            element = $(element);
            var type = element[0].type;
            var name = element[0].getAttribute('name'); //.name.value; //element.attr('name');
            var name = element.attr('name');
            //console.log("--", element[0].attributes.name.nodeValue, name, element[0]);

            if(!name || (type === 'radio' && !element.is(':checked'))){
                this.log("Skipping", element);
                return;
            }

            if (type === 'file') {
                this.log("Skipping File", element);
                return;
            }

            var value;
            if(type === 'checkbox'){
                value = element.prop('checked');
            } else if(element.hasClass('external-modules-rich-text-field')){
                var id = element.attr('id');
                console.log('ID: ' + id);
                value = tinymce.get(id).getContent();
            } else{
                value = element.val();
            }

            data[name] = value;
        });

        //this.log("DATA", data);
        return data;
    },


    // NOT USED HERE
    doAction: function (element) {
        var data = $(element).data();

        // Action MUST be defined or we won't do anything
        if (!data.action) {
            alert ("Invalid Button - missing action");
            return;
        }

        // Do the ajax call
        $.ajax({
            method: "POST",
            url: this.url,
            data: data,
            dataType: "json"
        })
            .done(function (data) {
                // Data should be in format of:
                // data.result   true/false
                // data.message  (optional)  message to display.
                // data.callback (function to call)
                // data.delay    (delay before callbackup in ms)
                var cls = data.result ? 'alert-success' : 'alert-danger';

                // Render message if we have one
                if (data.message) {
                    var alert = $('<div></div>')
                        .addClass('alert')
                        .addClass(cls)
                        .css({"position": "fixed", "top": "5px", "left": "2%", "width": "96%", "display":"none"})
                        .html(data.message)
                        .prepend("<a href='#' class='close' data-dismiss='alert'>&times;</a>")
                        .appendTo('#external-modules-configure-modal')
                        .show(500);

                    setTimeout(function(){
                        console.log('Hiding in 500', alert);
                        alert.hide(500);
                    }, 5000);
                }

                if (data.callback) {
                    var delay = data.delay ? data.delay : 0;

                    //since configuration is set, set the defaults for the first configuration
                    setTimeout(window[data.callback](), delay);
                }
            })
            .fail(function () {
                alert("error");
            })
            .always(function() {
                GiftCardRewardConfig.getStatus();
            });
    },




    log: function() {
        if (!this.isDev) return;

        // Make console logging more resilient to Redmond
        try {
            console.log.apply(this,arguments);
        } catch(err) {
            // Error trying to apply logs to console (problem with IE11)
            try {
                console.log(arguments);
            } catch (err2) {
                // Can't even do that!  Argh - no logging
            }
        }
    }

};
