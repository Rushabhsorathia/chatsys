<?php

namespace Chatsys\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Response;
use App\Models\User;
use App\Models\ChMessage as Message;
use App\Models\ChFavorite as Favorite;
use App\Models\ChChannel as Channel;
use Chatsys\Facades\ChatsysMessenger as Chatsys;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as FacadesRequest;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class MessagesController extends Controller
{
    protected $perPage = 30;

    /**
     * Authenticate the connection for pusher
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function pusherAuth(Request $request)
    {
        return Chatsys::pusherAuth(
            $request->user(),
            Auth::user(),
            $request['channel_name'],
            $request['socket_id']
        );
    }

    /**
     * Returning the view of the app with the required data.
     *
     * @param string $channel_id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function index($channel_id = null)
    {
        $messenger_color = Auth::user()->messenger_color;

        if(!Auth::user()->channel_id){
            Chatsys::createPersonalChannel();
        }

        return view('Chatsys::pages.app', [
            'channel_id' => $channel_id ?? 0,
            'channel' => $channel_id ? Channel::where('id', $channel_id)->first() : null,
            'messengerColor' => $messenger_color ? $messenger_color : Chatsys::getFallbackColor(),
            'dark_mode' => Auth::user()->dark_mode < 1 ? 'light' : 'dark',
        ]);
    }


    /**
     * Fetch data (user, favorite.. etc).
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function idFetchData(Request $request)
    {
        $fetch = null;
        $channel_avatar = null;

        $favorite = Chatsys::inFavorite($request['channel_id']);
        $channel = Channel::find($request['channel_id']);

        if(!$channel) return Response::json([
            'message' => "This chat channel doesn't exist!"
        ]);

        $allow_loading = $channel->owner_id === Auth::user()->id
            || in_array(Auth::user()->id, $channel->users()->pluck('id')->all());
        if(!$allow_loading) return Response::json([
            'message' => "You haven't joined this chat channel!"
        ]);

        // check if this channel is a group
        if(isset($channel->owner_id)){
            $fetch = $channel;
            $channel_avatar = Chatsys::getChannelWithAvatar($channel)->avatar;
        } else {
            $fetch = Chatsys::getUserInOneChannel($request['channel_id']);
            if($fetch){
                $channel_avatar = Chatsys::getUserWithAvatar($fetch)->avatar;
            }
        }

        $infoHtml = view('Chatsys::layouts.info', [
            'channel' => $channel,
        ])->render();

        return Response::json([
            'infoHtml' => $infoHtml,
            'favorite' => $favorite,
            'fetch' => $fetch ?? null,
            'channel_avatar' => $channel_avatar ?? null,
        ]);
    }

    /**
     * This method to make a links for the attachments
     * to be downloadable.
     *
     * @param string $fileName
     * @return \Symfony\Component\HttpFoundation\StreamedResponse|void
     */
    public function download($fileName)
    {
        $filePath = config('Chatsys.attachments.folder') . '/' . $fileName;
        if (Chatsys::storage()->exists($filePath)) {
            return Chatsys::storage()->download($filePath);
        }
        return abort(404, "Sorry, File does not exist in our server or may have been deleted!");
    }

    /**
     * Send a message to database
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function send(Request $request)
    {
        // default variables
        $error = (object)[
            'status' => 0,
            'message' => null
        ];
        $attachment = null;
        $attachment_title = null;

        // if there is attachment [file]
        if ($request->hasFile('file')) {
            // allowed extensions
            $allowed_images = Chatsys::getAllowedImages();
            $allowed_files  = Chatsys::getAllowedFiles();
            $allowed        = array_merge($allowed_images, $allowed_files);

            $file = $request->file('file');
            // check file size
            if ($file->getSize() < Chatsys::getMaxUploadSize()) {
                if (in_array(strtolower($file->extension()), $allowed)) {
                    // get attachment name
                    $attachment_title = $file->getClientOriginalName();
                    // upload attachment and store the new name
                    $attachment = Str::uuid() . "." . $file->extension();
                    $file->storeAs(config('Chatsys.attachments.folder'), $attachment, config('Chatsys.storage_disk_name'));
                } else {
                    $error->status = 1;
                    $error->message = "File extension not allowed!";
                }
            } else {
                $error->status = 1;
                $error->message = "File size you are trying to upload is too large!";
            }
        }

        if (!$error->status) {
            $lastMess = Message::where('to_channel_id', $request['channel_id'])->latest()->first();
            $message = Chatsys::newMessage([
                'from_id' => Auth::user()->id,
                'to_channel_id' => $request['channel_id'],
                'body' => htmlentities(trim($request['message']), ENT_QUOTES, 'UTF-8'),
                'attachment' => ($attachment) ? json_encode((object)[
                    'new_name' => $attachment,
                    'old_name' => htmlentities(trim($attachment_title), ENT_QUOTES, 'UTF-8'),
                ]) : null,
            ]);

            // load user info
            $message->user_avatar = Auth::user()->avatar;
            $message->user_name = Auth::user()->name;
            $message->user_email = Auth::user()->email;

            $messageData = Chatsys::parseMessage($message, null, $lastMess ? $lastMess->from_id !== Auth::user()->id : true);

            Chatsys::push("private-Chatsys.".$request['channel_id'], 'messaging', [
                'from_id' => Auth::user()->id,
                'to_channel_id' => $request['channel_id'],
                'username'=>Auth::user()->name,
                'message' => Chatsys::messageCard($messageData, true)
            ]);
            $to_id =  DB::table('ch_channel_user')
            ->where('channel_id', $request['channel_id'])
            ->where('user_id', '!=', Auth::user()->id)
            ->pluck('user_id');
            Chatsys::push("private-Chatsys.123-321-123-321-123-321", 'new-user', [
                'to_id'=> $to_id,
                'from_id' => Auth::user()->id,
                'to_channel_id' => $request['channel_id'],
                'username'=>Auth::user()->name,
                'message' => Chatsys::messageCard($messageData, true)
            ]);

        }

        // send the response
        return Response::json([
            'status' => '200',
            'error' => $error,
            'message' => Chatsys::messageCard(@$messageData),
            'tempID' => $request['temporaryMsgId'],
        ]);
    }

    /**
     * fetch [user/group] messages from database
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function fetch(Request $request)
    {
        $query = Chatsys::fetchMessagesQuery($request['id'])->latest();
        $messages = $query->paginate($request->per_page ?? $this->perPage);
        $totalMessages = $messages->total();
        $lastPage = $messages->lastPage();
        $response = [
            'total' => $totalMessages,
            'last_page' => $lastPage,
            'last_message_id' => collect($messages->items())->last()->id ?? null,
            'messages' => '',
        ];

        // if there is no messages yet.
        if ($totalMessages < 1) {
            $response['messages'] ='<p class="message-hint center-el"><span>Say \'hi\' and start messaging</span></p>';
            return Response::json($response);
        }
        if (count($messages->items()) < 1) {
            $response['messages'] = '';
            return Response::json($response);
        }
        $allMessages = null;
        $prevMess = null;
        foreach ($messages->reverse() as $message) {
            $allMessages .= Chatsys::messageCard(
                Chatsys::parseMessage($message, null, $prevMess ? $prevMess->from_id != $message->from_id : true)
            );
            $prevMess = $message;
        }
        $response['messages'] = $allMessages;
        return Response::json($response);
    }

    /**
     * Make messages as seen
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function seen(Request $request)
    {
        // make as seen
        $seen = Chatsys::makeSeen($request['channel_id']);
        // send the response
        return Response::json([
            'status' => $seen,
        ], 200);
    }

    /**
     * Get contacts list (list of channels)
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function getContacts(Request $request)
    {
        $query =Channel::join('ch_messages', 'ch_channels.id', '=', 'ch_messages.to_channel_id')
        ->join('ch_channel_user', 'ch_channels.id', '=', 'ch_channel_user.channel_id')
        ->where('ch_channel_user.user_id', '=', Auth::user()->id)
        ->select('ch_channels.*', DB::raw('MAX(ch_messages.created_at) as latest_message_at'))
        ->groupBy('ch_channels.id')
        ->orderBy('latest_message_at', 'desc')
        ->paginate($request->per_page ?? $this->perPage);

            $channelsList = $query->items();
            // dd($query->toArray());
        if (count($channelsList) > 0) {
            $contacts = '';
            foreach ($channelsList as $channel) {
                $contacts .= Chatsys::getContactItem($channel);
            }
        } else {
            $contacts = '<p class="message-hint center-el"><span>Your contact list is empty</span></p>';
        }
        // dd($contacts);

        return Response::json([
            'contacts' => $contacts,
            'total' => $query->total() ?? 0,
            'last_page' => $query->lastPage() ?? 1,
        ], 200);
    }

    /**
     * Update user's list item data
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function updateContactItem(Request $request)
    {
		$channel_id = $request['channel_id'];

        // Get user data
        $channel = Channel::find($channel_id);
        if(!$channel){
            return Response::json([
                'message' => 'Channel not found!',
            ], 401);
        }
        $contactItem = Chatsys::getContactItem($channel);

        // send the response
        return Response::json([
            'contactItem' => $contactItem,
        ], 200);
    }

	/**
	 * Get channel_id by get or create new channel
	 *
	 * @param Request $request
	 * @return JsonResponse|void
	 */
	public function getChannelId(Request $request)
	{
		$user_id = $request['user_id'];
		$res = Chatsys::getOrCreateChannel($user_id);

		// send the response
		return Response::json($res, 200);
	}

    /**
     * Put a channel in the favorites list
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function favorite(Request $request)
    {
        $channel_id = $request['channel_id'];
        // check action [star/unstar]
        $favoriteStatus = Chatsys::inFavorite($channel_id) ? 0 : 1;
        Chatsys::makeInFavorite($channel_id, $favoriteStatus);

        // send the response
        return Response::json([
            'status' => @$favoriteStatus,
        ], 200);
    }

    /**
     * Get favorites list
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function getFavorites(Request $request)
    {
        $favoritesList = null;
        $favorites = Favorite::where('user_id', Auth::user()->id);
        foreach ($favorites->get() as $favorite) {
            $channel = Channel::find($favorite->favorite_id);

            $data = null;
            if($channel->owner_id){
                $data = Chatsys::getChannelWithAvatar($channel);
            } else {
                $user = Chatsys::getUserInOneChannel($channel->id);
                $data = Chatsys::getUserWithAvatar($user);
            }
            $favoritesList .= view('Chatsys::layouts.favorite', [
                'data' => $data,
                'channel_id' => $channel->id
            ]);
        }
        // send the response
        return Response::json([
            'count' => $favorites->count(),
            'favorites' => $favorites->count() > 0
                ? $favoritesList
                : 0,
        ], 200);
    }

    /**
     * Search in messenger
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function search(Request $request)
    {
        $getRecords = null;
        $input = trim(filter_var($request['input']));
        $records = User::where('id','!=',Auth::user()->id)
                    ->where('name', 'LIKE', "%{$input}%")
                    ->paginate($request->per_page ?? $this->perPage);
        foreach ($records->items() as $record) {
            $getRecords .= view('Chatsys::layouts.listItem', [
                'get' => 'search_item',
                'user' => Chatsys::getUserWithAvatar($record),
            ])->render();
        }
        if($records->total() < 1){
            $getRecords = '<p class="message-hint center-el"><span>Nothing to show.</span></p>';
        }
        // send the response
        return Response::json([
            'records' => $getRecords,
            'total' => $records->total(),
            'last_page' => $records->lastPage()
        ], 200);
    }

	/**
     * Get shared photos
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function sharedPhotos(Request $request)
    {
        $shared = Chatsys::getSharedPhotos($request['channel_id']);
        $sharedPhotos = null;

        // shared with its template
        for ($i = 0; $i < count($shared); $i++) {
            $sharedPhotos .= view('Chatsys::layouts.listItem', [
                'get' => 'sharedPhoto',
                'image' => Chatsys::getAttachmentUrl($shared[$i]),
            ])->render();
        }
        // send the response
        return Response::json([
            'shared' => count($shared) > 0 ? $sharedPhotos : '<p class="message-hint"><span>Nothing shared yet</span></p>',
        ], 200);
    }

    /**
     * Delete conversation
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteConversation(Request $request)
    {
        // delete
        $delete = Chatsys::deleteConversation($request['channel_id']);

        // send the response
        return Response::json([
            'deleted' => $delete ? 1 : 0,
        ], 200);
    }

    /**
     * Delete group chat
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteGroupChat(Request $request)
    {
        $channel_id = $request['channel_id'];


        $channel = Channel::findOrFail($channel_id);
        $channel->users()->detach();

        Chatsys::deleteConversation($channel_id);


        // send the response
        return Response::json([
            'deleted' => $channel->delete(),
        ], 200);
    }

    /**
     * Leave group chat
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function leaveGroupChat(Request $request)
    {
        $channel_id = $request['channel_id'];
        $user_id = $request['user_id'];

        // add last message
        $message = Chatsys::newMessage([
            'from_id' => Auth::user()->id,
            'to_channel_id' => $channel_id,
            'body' => Auth::user()->name . ' has left the group',
            'attachment' => null,
        ]);
        $message->user_avatar = Auth::user()->avatar;
        $message->user_name = Auth::user()->name;
        $message->user_email = Auth::user()->email;

        $messageData = Chatsys::parseMessage($message, null);

        Chatsys::push("private-Chatsys.".$channel_id, 'messaging', [
            'from_id' => Auth::user()->id,
            'to_channel_id' => $channel_id,
            'username'=>Auth::user()->name,
            'message' => Chatsys::messageCard($messageData, true)
        ]);

        // detach user
        $channel = Channel::findOrFail($channel_id);
        $channel->users()->detach($user_id);

        // send the response
        return Response::json([
            'left' => $channel ? 1 : 0,
        ], 200);
    }

    /**
     * Delete message
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function deleteMessage(Request $request)
    {
        // delete
        $delete = Chatsys::deleteMessage($request['id']);

        // send the response
        return Response::json([
            'deleted' => $delete ? 1 : 0,
        ], 200);
    }

    public function updateSettings(Request $request)
    {
        $msg = null;
        $error = $success = 0;

        // dark mode
        if ($request['dark_mode']) {
            $request['dark_mode'] == "dark"
                ? User::where('id', Auth::user()->id)->update(['dark_mode' => 1])  // Make Dark
                : User::where('id', Auth::user()->id)->update(['dark_mode' => 0]); // Make Light
        }

        // If messenger color selected
        if ($request['messengerColor']) {
            $messenger_color = trim(filter_var($request['messengerColor']));
            User::where('id', Auth::user()->id)
                ->update(['messenger_color' => $messenger_color]);
        }
        // if there is a [file]
        if ($request->hasFile('avatar')) {
            // allowed extensions
            $allowed_images = Chatsys::getAllowedImages();

            $file = $request->file('avatar');
            // check file size
            if ($file->getSize() < Chatsys::getMaxUploadSize()) {
                if (in_array(strtolower($file->extension()), $allowed_images)) {
                    // delete the older one
                    if (Auth::user()->avatar != config('Chatsys.user_avatar.default')) {
                        $avatar = Auth::user()->avatar;
                        if (Chatsys::storage()->exists($avatar)) {
                            Chatsys::storage()->delete($avatar);
                        }
                    }
                    // upload
                    $avatar = Str::uuid() . "." . $file->extension();
                    $update = User::where('id', Auth::user()->id)->update(['avatar' => $avatar]);
                    $file->storeAs(config('Chatsys.user_avatar.folder'), $avatar, config('Chatsys.storage_disk_name'));
                    $success = $update ? 1 : 0;
                } else {
                    $msg = "File extension not allowed!";
                    $error = 1;
                }
            } else {
                $msg = "File size you are trying to upload is too large!";
                $error = 1;
            }
        }

        // send the response
        return Response::json([
            'status' => $success ? 1 : 0,
            'error' => $error ? 1 : 0,
            'message' => $error ? $msg : 0,
        ], 200);
    }

    /**
     * Set user's active status
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function setActiveStatus(Request $request)
    {
        $activeStatus = $request['status'] > 0 ? 1 : 0;
        $status = User::where('id', Auth::user()->id)->update(['active_status' => $activeStatus]);
        return Response::json([
            'status' => $status,
        ], 200);
    }

    /**
     * Search users
     *
     * @param Request $request
     * @return JsonResponse|void
     */
    public function searchUsers(Request $request)
    {
        $getRecords = array();
        $input = trim(filter_var($request['input']));
        $records = User::where('id','!=',Auth::user()->id)
            ->where('name', 'LIKE', "%{$input}%")
            ->paginate($request->per_page ?? $this->perPage);
        foreach ($records->items() as $record) {
            $getRecords[] = array(
                "user" => $record,
                "view" => view('Chatsys::layouts.listItem', [
                    'get' => 'user_search_item',
                    'user' => Chatsys::getUserWithAvatar($record),
                ])->render()
            );
        }
        if($records->total() < 1){
            $getRecords = '<p class="message-hint"><span>Nothing to show.</span></p>';
        }
        // send the response
        return Response::json([
            'records' => $getRecords,
            'total' => $records->total(),
            'last_page' => $records->lastPage()
        ], 200);
    }


    public function createGroupChat(Request $request)
    {
        $msg = null;
        $error = $success = 0;

        $user_ids = array_map('intval', explode(',', $request['user_ids']));
        $user_ids[] = Auth::user()->id;
        $user_channel_ids= User::whereIn('id', $user_ids)->pluck('channel_id');
        // dd($user_channel_ids);
        $group_name = $request['group_name'];

        $new_channel = new Channel();
        $new_channel->name = $group_name;
        $new_channel->owner_id = Auth::user()->id;
        $new_channel->save();
        $new_channel->users()->sync($user_ids);

        // add first message
        $message = Chatsys::newMessage([
            'from_id' => Auth::user()->id,
            'to_channel_id' => $new_channel->id,
            'body' => Auth::user()->name . ' has created a new chat group: ' . $group_name,
            'attachment' => null,
        ]);
        $message->user_name = Auth::user()->name;
        $message->user_email = Auth::user()->email;

        $messageData = Chatsys::parseMessage($message, null);
        Chatsys::push("private-Chatsys.123-321-123-321-123-321", 'new-group', [
            'group_id'=>$new_channel->id,
            'users_id'=>$user_ids,
            'from_id' => Auth::user()->id,
            'to_channel_id' => $new_channel->id,
            'username'=>Auth::user()->name,
            'message' => Chatsys::messageCard($messageData, true)
        ]);
        Chatsys::push("private-Chatsys.".$new_channel->id, 'messaging', [
            'from_id' => Auth::user()->id,
            'to_channel_id' => $new_channel->id,
            'username'=>Auth::user()->name,
            'message' => Chatsys::messageCard($messageData, true)
        ]);


        // if there is a [file]
        if ($request->hasFile('avatar')) {
            // allowed extensions
            $allowed_images = Chatsys::getAllowedImages();

            $file = $request->file('avatar');
            // check file size
            if ($file->getSize() < Chatsys::getMaxUploadSize()) {
                if (in_array(strtolower($file->extension()), $allowed_images)) {
                    $avatar = Str::uuid() . "." . $file->extension();
                    $update = $new_channel->update(['avatar' => $avatar]);
                    $file->storeAs(config('Chatsys.channel_avatar.folder'), $avatar, config('Chatsys.storage_disk_name'));
                    $success = $update ? 1 : 0;
                } else {
                    $msg = "File extension not allowed!";
                    $error = 1;
                }
            } else {
                $msg = "File size you are trying to upload is too large!";
                $error = 1;
            }
        }

        return Response::json([
            'status' => $success ? 1 : 0,
            'error' => $error ? 1 : 0,
            'message' => $error ? $msg : 0,
            'channel' => $new_channel
        ], 200);
    }

}
