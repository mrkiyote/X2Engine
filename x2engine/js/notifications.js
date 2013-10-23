/*****************************************************************************************
 * X2CRM Open Source Edition is a customer relationship management program developed by
 * X2Engine, Inc. Copyright (C) 2011-2013 X2Engine Inc.
 * 
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU Affero General Public License version 3 as published by the
 * Free Software Foundation with the addition of the following permission added
 * to Section 15 as permitted in Section 7(a): FOR ANY PART OF THE COVERED WORK
 * IN WHICH THE COPYRIGHT IS OWNED BY X2ENGINE, X2ENGINE DISCLAIMS THE WARRANTY
 * OF NON INFRINGEMENT OF THIRD PARTY RIGHTS.
 * 
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS
 * FOR A PARTICULAR PURPOSE.  See the GNU Affero General Public License for more
 * details.
 * 
 * You should have received a copy of the GNU Affero General Public License along with
 * this program; if not, see http://www.gnu.org/licenses or write to the Free
 * Software Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA
 * 02110-1301 USA.
 * 
 * You can contact X2Engine, Inc. P.O. Box 66752, Scotts Valley,
 * California 95067, USA. or at email address contact@x2engine.com.
 * 
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 * 
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "Powered by
 * X2Engine" logo. If the display of the logo is not reasonably feasible for
 * technical reasons, the Appropriate Legal Notices must display the words
 * "Powered by X2Engine".
 *****************************************************************************************/


var pageTitle = '';


function updateHistory() {
	$('.action.list-view').each(function(i) {
		$.fn.yiiListView.update($(this).attr('id'));
	});
}

// title marquee jquery plugin
$(function() {
	var opts = {
		titleTimeout: null,
		message: '',
		titleOffset: 0,
		speed: 200,
		softStop: false
	};

	// var pageTitle =

	var methods = {
		set: function(msg) {
			if (typeof msg != 'undefined')
				opts.message = msg;
		},
		start: function(msg) {
			if (typeof msg != 'undefined') {
				opts.softStop = false;
				opts.message = msg;
				clearInterval(opts.titleTimeout);
				opts.titleTimeout = setInterval(function() {
					methods['tick']();
				}, opts.speed);
			}
		},
		tick: function() {
			++opts.titleOffset;
			if (opts.titleOffset >= opts.message.length) {
				opts.titleOffset = 0;
				if (opts.softStop) {
					methods['stop']();
				}
			}
			var newTitle = opts.message.substring(opts.titleOffset) + ' ' + 
                opts.message.substring(0, opts.titleOffset);

			document.title = newTitle;
		},
		pause: function() {
			clearInterval(opts.titleTimeout);
		},
		stop: function() {
			clearInterval(opts.titleTimeout);
			opts.titleOffset = 0;
			opts.softStop = false;
			document.title = pageTitle;
		},
		softStop: function() {
			opts.softStop = true;
		}
	};

	$.fn.titleMarquee = function(method) {
		if (methods[method])
			return methods[ method ].apply(this, Array.prototype.slice.call(arguments, 1));
		else
			$.error('Method ' + method + ' does not exist on titleMarquee');
	};
});


$(function() {
	/**
	 * X2Engine Inter-Window Communication system
	 * Based on jStorage library
	 *
	 * Each window chooses a random unique ID and checks for key "masterId"
	 * If it's not set then the window sets it to it's own ID and begins making
	 * AJAX requests for notifications and chat. This check is done once per
	 * second, and AJAX requests are made at a user-specified rate.
	 *
	 * When the master window is closed, it unsets "masterId" so another window
	 * can take over. If for some reason this event doesn't fire, "masterId"
	 * still expires after 2 seconds
	 *
	 */

	// initialize variables
	var hasFocus = true;
	var newNotif = null,
		//notifCount = 0,
		notifTimeout,
		// notifUpdateInterval = 1500,
		notifViewTimeout,
		lastNotifId = 0,
		lastEventId = 0,
		lastTimestamp = 0;
	
	x2.fetchNotificationUpdates = true;//!x2.DEBUG;


	iwcMode = $.jStorage.storageAvailable();
    windowId = +new Date() + Math.floor(Math.random() * 1000); // generate ID from timestamp and random number
    masterId = null;

	if (iwcMode) {	// find out of this is going to work
        //x2.DEBUG && console.log ('notifications: is iwcMode');

		/**
		 * Looks for a non-expired masterId entry in local storage.
		 * If there is no current masterId, sets it to current window's ID and
		 * starts making AJAX requests. Otherwise, does nothing.
		 *
		 * If this is the master window, masterId will never expire as long as
		 * checkMasterId() continues running, since it resets the key's TTL.
		 */
		function checkMasterId(forceUpdate) {	// check if there's currently a master window
			if (masterId == windowId) {
				$.jStorage.setTTL('iwcMasterId', x2.notifUpdateInterval + 2000);	// still here, update masterId expiration
			} else {
				masterId = $.jStorage.get('iwcMasterId', null);
				if (masterId == null) {	// no incumbent master window, time to step up!
					masterId = windowId;
					$.jStorage.set('iwcMasterId', masterId, {TTL: x2.notifUpdateInterval + 2000});
				}
			}

			if (forceUpdate)
				getUpdates(true);	// check for notifs but don't update other windows
			else if (masterId == windowId)
				getUpdates();	// check for notifs
			else
				notifTimeout = setTimeout(checkMasterId, x2.notifUpdateInterval);	// leave the AJAX to the master window, but keep an eye on him
		}

		checkMasterId(true);	// always get updates on startup, because the master
		// window will only broadcast ones it considers new

		// var checkMasterIdTimeout = setInterval(checkMasterId,notifUpdateInterval);

		/**
		 * Subscribe to various IWC channels using jStorage plugin.
		 * Each channel represents a particular event type.
		 *
		 * Note that events from the same window are ignored to maintain cross
		 * browser consistency in Opera and IE.
		 */
		// new notif received, add it to the list, update count
		$.jStorage.subscribe("x2iwc_notif", function(ch, payload) {
			if (payload.origin == windowId)
				return;
			/*if (payload.notifCount)
				notifCount = payload.notifCount;*/
			if (payload.data) {
				addNotifications(payload.data, false);
				openNotifications();
			}
		});
		// notif box toggled open/closed
		$.jStorage.subscribe("x2iwc_toggle_notif", function(ch, payload) {
			if (payload.origin == windowId)
				return;
			if (payload.show)
				openNotifications();
			else
				closeNotifications();
		});
		// notif deleted, remove it and add the next notif, update count
		$.jStorage.subscribe("x2iwc_notif_delete", function(ch, payload) {
            //x2.DEBUG && console.log ('x2iwc_notif_delete: payload = ');
            //x2.DEBUG && console.log (payload);
            //x2.DEBUG && console.log (windowId);
			if (payload.origin == windowId)
				return;

			//notifCount = payload.notifCount;
			removeNotification(payload.id)
			//notifCount = $('#notifications').children ('.notif').length; 

			if (payload.nextNotif)
				addNotifications(payload.nextNotif, true);
		});

		// all notifs deleted, remove them and update count
        //x2.DEBUG && console.log ('x2iwc_notif_delete_all: setup');
		$.jStorage.subscribe("x2iwc_notif_delete_all", function(ch, payload) {
            //x2.DEBUG && console.log ('x2iwc_notif_delete_all: payload, windowId = ');
            //x2.DEBUG && console.log (payload);
            //x2.DEBUG && console.log (windowId);
			if (payload.origin == windowId)
				return;

			removeAllNotifications ()
		});

		// new chat message, add it
		$.jStorage.subscribe("x2iwc_chat", function(ch, payload) {
			if (payload.origin == windowId)
				return;
			addChatMessages(payload.data);

		});

		// remove masterId when we close the window or navigate away
		$(window).unload(function() {
			masterId = $.jStorage.get('iwcMasterId', null);	// get the current masterId in case that has changed somehow
			if (windowId == masterId)
				$.jStorage.deleteKey('iwcMasterId');
		});
	} else {
		getUpdates();		// no IWC, we're on our own here so we gotta just do the AJAX
	}

	pageTitle = document.title;

	// listen for window focus/blur for stupid browsers that can't handle document.hasFocus()
	$(window).bind('blur focusout', function() {
		hasFocus = false;
	});
	$(window).bind('focus focusin', function() {
		hasFocus = true;
	});

	$(document).bind('x2.newNotifications', function(e) {
		if ($('#notif-box').not(':visible'))
			openNotifications();
	});
	$('#main-menu-notif').click(function() {
		var show = !$('#notif-box').is(':visible')	// if it's hidden, show it
		if (show)
			openNotifications();
		else
			closeNotifications();

		$.jStorage.publish("x2iwc_toggle_notif", {show: show, origin: windowId});

		return false;
	});

	$(document).click(function(e) {
		if (!$(e.target).is('#notif-box, #notif-box *')) {
			closeNotifications();
			$.jStorage.publish("x2iwc_toggle_notif", {show: false});
		}
	});

	$('#notif-box .close').click(function() {
		//console.log ('close');											
		$('#notif-box').fadeOut(300);
		// $.fn.titleMarquee('softStop');
	});

	$('#notif-clear-all').on ('click', function (evt) {
        evt.preventDefault ();
        if (!window.confirm (x2.notifications.translations['clearAll'])) return;
		$.ajax({
			type: 'GET',
			url: yii.scriptUrl + '/notifications/deleteAll',
			success: function(response) {
                removeAllNotifications ();
			},
			complete: function() {
				if (iwcMode) {	// tell other windows to do the same
					$.jStorage.publish("x2iwc_notif_delete_all", {
						origin: windowId
					});
				}
			}
		});
    })

	/**
	 * Deletes a notification.
	 * Makes AJAX delete request, calls removeNotification() to remove it from
	 * the DOM and publishes the deletion to other windows.
	 */
	$('#notif-box').delegate('.notif .close', 'click', function(e) {
		e.stopPropagation();
		// if($(this).is(":animated"))
		// return;
		var notifId = $(this).parent().data('id');

		var nextNotif = false;

		removeNotification(notifId);	// remove notif from the list
		var notifCount = $('#notifications').children ('.notif').length;

		getNextNotif = notifCount > 9 ? '1' : null;	// load the next notification if there are any more

		$.ajax({
			type: 'GET',
			url: yii.scriptUrl + '/notifications/delete',
			data: {
				id: notifId,
				getNext: getNextNotif,
				lastNotifId: lastNotifId
			},
			success: function(response) {
				try {
					data = $.parseJSON(response);
					if (data.notifData) {
						nextNotif = data.notifData;
						addNotifications(nextNotif, true);		// append next notification to the notif box
					}

				} catch (e) {
				}	// ignore if JSON is being an idiot
			},
			complete: function() {
				if (iwcMode) {	// tell other windows to do the same
					$.jStorage.publish("x2iwc_notif_delete", {
						origin: windowId,
						id: notifId,
						nextNotif: nextNotif,
						notifCount: notifCount
					});
				}
			}
		});
	});

    // Used by viewNotifications.php
    x2.notifications.triggerNotifRemoval = function (id) {
		$('#notifications .notif').each(function() {
			if ($(this).data('id') === id) {
                $(this).find ('.close').click ();
			}
		});
    };

	/**
	 * Submits a chat message.
	 * Makes AJAX request, calls addChatMessages() and publishes the message
	 * to other windows.
	 */
	$('#chat-submit').click(function() {
		$.ajax({
			type: 'POST',
			url: yii.scriptUrl + '/site/newMessage',
			data: $(this).closest('form').serialize()
		}).done(function(response) {
			$('#chat-message').val('');

			var chatData = $.parseJSON(response);

			if (chatData != null) {
				addChatMessages(chatData);
				if (iwcMode) {	// tell other windows about it
					$.jStorage.publish("x2iwc_chat", {
						origin: windowId,
						data: chatData
					});
				}
			}
		});
		return false;
	});


	/**
	 * Checks for notifications or chat updates via AJAX and calls
	 * addNotification or whatever, then publishes to the other windows via
	 * the IWC system
	 */
	function getUpdates(firstCall) {
		if(x2.fetchNotificationUpdates) {
			$.ajax({
				type: 'GET',
				url: yii.scriptUrl + '/notifications/get',
				data: {
					lastNotifId: lastNotifId,
					lastEventId: lastEventId,
					lastTimestamp: lastTimestamp
				},
				dataType: 'json'
			}).done(function(response) {

				if (iwcMode)
					notifTimeout = setTimeout(checkMasterId, x2.notifUpdateInterval);	// call checkMasterId, which will then call getUpdates
				else
					notifTimeout = setTimeout(getUpdates, x2.notifUpdateInterval);		// there's no IWC, so call getUpdates directly

				if (response == null || typeof response != 'object')	// if there's no new data, we're done
					return;

				if(typeof response.sessionError != 'undefined' && hasFocus) {
					x2.fetchNotificationUpdates = confirm(response.sessionError);
					if(x2.fetchNotificationUpdates) {
						window.location = window.location;
					}
				}

                //x2.DEBUG && console.log ('ajax response');
				
				try {
					var data = response; //$.parseJSON(response);

					if (data.notifData.length) {
						//notifCount = data.notifCount;
						addNotifications(data.notifData, false, firstCall);		// add new notifications to the notif box (prepend)
						var notifCount = $('#notifications').children ('.notif').length; 

						if (!firstCall) {
							playNotificationSound();
							openNotifications();

							if (iwcMode) {	// tell other windows about it
								$.jStorage.publish("x2iwc_notif", {
									origin: windowId,
									data: data.notifData,
									//notifCount: data.notifCount
									notifCount: notifCount
								});
							}
						}
					}
					if (data.chatData) {
						if (iwcMode && !firstCall) {	// tell other windows about it
							$.jStorage.publish("x2iwc_chat", {
								origin: windowId,
								data: data.chatData
							});
						}
						addChatMessages(data.chatData);
					}
				} catch (e) {
				}	// ignore if JSON is being an idiot
			}).fail(function() {
				clearTimeout(notifTimeout);
			});
		}
		return false;
	}

	function playNotificationSound(){
        $('#notificationSound').attr("src", yii.notificationSoundPath);
        var sound = $("#notificationSound")[0];
        if (Modernizr.audio) sound.play();
	}

	/**
	 * Opens the notification box and starts a timer to mark the notifications
	 * as viewed after 2 seconds (unless the user closes the box before then)
	 */
	function openNotifications() {

		notifViewTimeout = setTimeout(function() {

			var notifIds = [];

			$('#notifications .notif.unviewed').each(function() {	// loop through notifs, collect IDs (ignore if already viewed)
				notifIds.push('id[]=' + $(this).removeClass('unviewed').data('id'));
			});
			if (notifIds.length) {
				$.ajax({
					type: 'GET',
					url: yii.scriptUrl + '/notifications/markViewed',
					data: encodeURI(notifIds.join('&'))
				});
			}
		}, 2000);

		$('#notif-box').fadeIn({
			duration: 300,
			step: function () { // resize multiline notifications and reposition x button
				$(this).find('.notif').each (function () {
					$(this).height ($(this).find ('.msg').height ());
					$(this).find ('.close').position ({
						my: "right-4 top+4",
						at: "right top",
						of: $(this)
					});
				});
			},
			complete: function () {
                $('#notif-box-shadow-correct').show ();
				$('#notif-box-shadow-correct').position ({ // IE bug fix, forces repaint
					my: "left-20 top",
					at: "left bottom",
					of: $(this)
				});
			}
		});
	}

	/**
	 * Closes the box and cancels the "mark as viewed" timer
	 */
	function closeNotifications() {
		clearTimeout(notifViewTimeout);
		$('#notif-box').fadeOut(300);
        $('#notif-box-shadow-correct').hide ();
		// $.fn.titleMarquee('softStop');
	}

	function checkIfAreadyReceived (notifId) {
		var alreadyReceived = false;
		$('#notifications .notif').each(function() {
			if ($(this).data('id') == notifId) {
				alreadyReceived = true;
				return false;
			}
		});
		return alreadyReceived;
	}

	/**
	 * Generates notifications HTML from notifData and adds them to the DOM,
	 * and updates the notification count.
	 * Also triggers x2.newNotifications event (which opens the box)
	 */
	function addNotifications(notifData, append, firstCall) {
        firstCall = typeof firstCall === 'undefined' ? false : true;
        //x2.DEBUG && console.log ('addNotifications');

		var newNotif = false;

		var $notifBox = $('#notifications');
        var newNotifNum = 0;

		// loop through the notifications backwards (they're ordered by ID descending, so start with the oldest)
		for (var i = notifData.length - 1; i >= 0; --i) {
			var timeNow = new Date();

			var notifId = notifData[i].id;
			//console.log ('addNotifications: notifId = ' + notifId);

			if (checkIfAreadyReceived (notifId)) continue;
            newNotifNum++;
			
			if (notifData[i].type == 'voip_call' && timeNow.getTime() / 1000 - notifData[i].timestamp < 20 && 
				windowId == masterId) { // Screen pop only if less than 20 seconds ago and master window

				window.open (yii.baseUrl+'/index.php/contacts/'+notifData[i].modelId);
			}

			var notif = $(document.createElement('div'))
					.addClass('notif')
					.html('<div class="msg">' + notifData[i].text + '</div><div class="close">x</div>')
					.data('id', notifId);

			if (append)
				notif.appendTo($notifBox);
			else
				notif.prependTo($notifBox);

			if (notifData[i].viewed == 0) {
				notif.addClass('unviewed');
				newNotif = true;
			}

			$notifBox.find ('.notif').each (function () {
				$(this).height ($(this).find ('.msg').height ());
				$(this).find ('.close').position ({
					my: "right-4 top+4",
					at: "right top",
					of: $(this)
				});
			});

		}

		while ($notifBox.find('.notif').length > 10)		// remove older messages if it gets past 10
			$notifBox.find('.notif:last').remove();

		if (notifData.length && !append)
			lastNotifId = notifData[0].id;

        // increment notification number if it's new
        if (!append && !firstCall && newNotifNum > 0) incrNotif (newNotifNum);

		if (newNotif && !append)
			$(document).trigger('x2.newNotifications');
	}

	function removeAllNotifications(id) {
		$('#notifications .notif').remove ();
        $('#notif-box-shadow-correct').position ({ // IE bug fix, forces repaint
            my: "left-20 top",
            at: "left bottom",
            of: $('#notif-box')
        });

        var notifCount = 0;
		$('#main-menu-notif span').html(notifCount);

        toggleClearAllViewAllNoNotif (notifCount);
	}

	/**
	 * Finds a notification by its id and removes it from the DOM
	 */
	function removeNotification(id) {

		$('#notifications .notif').each(function() {
			if ($(this).data('id') == id) {
				$(this).remove();
				$('#notif-box-shadow-correct').position ({ // IE bug fix, forces repaint
					my: "left-20 top",
					at: "left bottom",
					of: $('#notif-box')
				});
				return false;
			}
		});

		//countNotifications();
        decrNotif ();
	}

    function toggleClearAllViewAllNoNotif (notifCount) {
		var showViewAll = false,
			showNoNotif = false;

		if (notifCount < 1)
			showNoNotif = true;
		else if (notifCount > 10)
			showViewAll = true;

		$("#notif-view-all").toggle(showViewAll);
		$("#notif-clear-all").toggle(!showNoNotif);
		$('#no-notifications').toggle(showNoNotif);

    }

    /*
    Increment number in ui indicating number of notifications by newNotifNum
    */
    function incrNotif (newNotifNum) {
        //x2.DEBUG && console.log ('incrNotif');
        var notifCount = parseInt ($('#main-menu-notif span').html()) + newNotifNum;
		$('#main-menu-notif span').html(notifCount);

        toggleClearAllViewAllNoNotif (notifCount);
    }

    /*
    Decrement number in ui indicating number of notifications
    */
    function decrNotif () {
        //x2.DEBUG && console.log ('decrNotif');
        var notifCount = parseInt ($('#main-menu-notif span').html()) - 1;
		$('#main-menu-notif span').html(notifCount);

        toggleClearAllViewAllNoNotif (notifCount);
    }

	/**
	 * See how many notifications are in the list, update the counter,
	 * and decide whether to show the "no notifications" thingy
	 */
	function countNotifications() {
		//notifCount = Math.max(0, notifCount);
		var notifCount = $('#notifications').children ('.notif').length; 

		$('#main-menu-notif span').html(notifCount);

		var showViewAll = false,
			showNoNotif = false;

		if (notifCount < 1)
			showNoNotif = true;
		else if (notifCount > 10)
			showViewAll = true;

		$("#notif-view-all").toggle(showViewAll);
		$('#no-notifications').toggle(showNoNotif);
	}

	/**
	 * Processes chat JSON data, generates chat entries and adds them to the
	 * chat window. Scrolls to the bottom of the chat window (unless the user
	 * has manually scrolled up)
	 */
	function addChatMessages(messages) {
		// var messages = $.parseJSON(response);
		if (messages == null)
			messages = [];

		// var scrollToBottom = $('#chat-box').prop('scrollTop') >= $('#chat-box').prop('scrollHeight') - $('#chat-box').height();

		var feedOrder = yii.profile.activityFeedOrder;
		if(feedOrder==0){
			scrollToBottom=true;
		}else{
			scrollToBottom=false;
		}
		//scrollToBottom = !feedOrder;


		for (var i in messages) {
			//console.debug(messages[i][0]);
			if (messages[i].length != 5)	// skip messages we already have
				continue;

			if (messages[i][0] > lastEventId)
				lastEventId = messages[i][0];
			if (messages[i][1] > lastTimestamp)
				lastTimestamp = messages[i][1];

			var msgHtml = '<div class="message">';
			msgHtml += messages[i][3] + ' <span class="comment-age">(' + messages[i][4] + ')</span>' +
					'</div>';

			//if top is true prepend to the list printing out in reverse order
			if(feedOrder==1)
				$('#chat-box').prepend(msgHtml);
			else
				$('#chat-box').append(msgHtml);	// add new messages to chat window
		}

		if (messages.length > 0 && scrollToBottom)
			$('#chat-box').prop('scrollTop', $('#chat-box').prop('scrollHeight')); // scroll to bottom of window
	}



	/**
	 * Listens for AJAX requests that were redirected due to session
	 * timeout and navigates to the login page.
	 */
	$('body').bind('ajaxSuccess', function(event, request, settings) {
		if (request.getResponseHeader('REQUIRES_AUTH') == '1') {
			window.location = window.location;
		}
		;
	});

});

