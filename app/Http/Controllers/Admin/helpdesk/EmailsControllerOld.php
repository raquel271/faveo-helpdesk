<?php

namespace App\Http\Controllers\Admin\helpdesk;

// controllers
use App\Http\Controllers\Controller;
// request
use App\Http\Requests\helpdesk\EmailsEditRequest;
use App\Http\Requests\helpdesk\EmailsRequest;
use App\Http\Requests\helpdesk\Mail\MailRequest;
// model
use App\Model\helpdesk\Agent\Department;
use App\Model\helpdesk\Email\Emails;
use App\Model\helpdesk\Manage\Help_topic;
use App\Model\helpdesk\Settings\Email;
use App\Model\helpdesk\Ticket\Ticket_Priority;
use App\Model\helpdesk\Utility\MailboxProtocol;
// classes
use Crypt;
use Exception;
use Illuminate\Http\Request;
use Lang;

/**
 * ======================================
 * EmailsController.
 * ======================================
 * This Controller is used to define below mentioned set of functions applied to the Emails in the system.
 *
 * @author Ladybird <info@ladybirdweb.com>
 */
class EmailsControllerOld extends Controller
{
    /**
     * Defining constructor variables.
     *
     * @return type
     */
    public function __construct()
    {
        $this->middleware('auth');
        $this->middleware('roles');
    }

    /**
     * Display a listing of the Emails.
     *
     * @param type Emails $emails
     *
     * @return type view
     */
    public function index(Emails $email)
    {
        try {
            // fetch all the emails from emails table
            $emails = $email->get();

            return view('themes.default1.admin.helpdesk.emails.emails.index', compact('emails'));
        } catch (Exception $e) {
            return redirect()->back()->with('fails', $e->getMessage());
        }
    }

    /**
     * Show the form for creating a new resource.
     *
     * @param type Department      $department
     * @param type Help_topic      $help
     * @param type Priority        $priority
     * @param type MailboxProtocol $mailbox_protocol
     *
     * @return type Response
     */
    public function create(Department $department, Help_topic $help, Ticket_Priority $ticket_priority, MailboxProtocol $mailbox_protocol)
    {
        try {
            // fetch all the departments from the department table
            $departments = $department->get();
            // fetch all the helptopics from the helptopic table
            $helps = $help->get();
            // fetch all the types of priority from the ticket_priority table
            $priority = $ticket_priority->get();
            // fetch all the types of mailbox protocols from the mailbox_protocols table
            $mailbox_protocols = $mailbox_protocol->get();

            $service = new \App\Model\MailJob\MailService();
            $services = $service->lists('name', 'id')->toArray();

            // return with all the table data
            return view('themes.default1.admin.helpdesk.emails.emails.create', compact('mailbox_protocols', 'priority', 'departments', 'helps', 'services'));
        } catch (Exception $e) {
            // return error messages if any
            return redirect()->back()->with('fails', $e->getMessage());
        }
    }

    /**
     * Check for email input validation.
     *
     * @param EmailsRequest $request
     *
     * @return int
     */
    public function validatingEmailSettings(MailRequest $request, $id = '')
    {
        $service_request = $request->except('sending_status', '_token', 'email_address', 'email_name', 'password', 'department', 'priority', 'help_topic', 'fetching_protocol', 'fetching_host', 'fetching_port', 'fetching_encryption', 'imap_authentication', 'sending_protocol', 'sending_host', 'sending_port', 'sending_encryption', 'smtp_authentication', 'internal_notes', '_wysihtml5_mode', 'code');
        $service = $request->input('sending_protocol');
        $send = 0;
        $imap_check[0] = 0;
        if ($request->input('imap_validate') == 'on') {
            $validate = '/validate-cert';
        } elseif (!$request->input('imap_validate')) {
            $validate = '/novalidate-cert';
        }
        if ($request->input('fetching_status') == 'on') {
            try {
                $imap_check = $this->getImapStream($request, $validate);
            } catch (Exception $ex) {
                \Log::error($ex->getMessage());
                $result = ['fails' => $ex->getMessage()];

                return response()->json(compact('result'));
            }
            if ($imap_check[0] == 0) {
                $response = Lang::get('lang.incoming_email_connection_failed_please_check_email_credentials_or_imap_settings');
            }
        } else {
            $imap_check[0] = 1;
        }
        if ($request->input('sending_status') == 'on') {
            $this->emailService($service, $service_request);
            try {
                $send = $this->sendDiagnoEmail($request);
            } catch (Exception $ex) {
                \Log::error($ex->getMessage());
                $result = ['fails' => $ex->getMessage()];

                return response()->json(compact('result'));
            }
            if ($send === 0) {
                $response = Lang::get('lang.outgoing_email_connection_failed');
            }
        } else {
            $send = 1;
        }
        if ($send === 1 && $imap_check[0] === 1) {
            $this->store($request, $imap_check, $service_request, $id);
        }

        return $this->jsonResponse($send, $imap_check);
    }

    public function sendDiagnoEmail($request)
    {
        try {
            $mailservice_id = $request->input('sending_protocol');
            $driver = $this->getDriver($mailservice_id);
            $username = $request->input('email_address');
            $password = $request->input('password');
            $name = $request->input('email_name');
            $host = $request->input('sending_host');
            $port = $request->input('sending_port');
            $enc = $request->input('sending_encryption');
            $service_request = $request->except('sending_status', '_token', 'email_address', 'email_name', 'password', 'department', 'priority', 'help_topic', 'fetching_protocol', 'fetching_host', 'fetching_port', 'fetching_encryption', 'imap_authentication', 'sending_protocol', 'sending_host', 'sending_port', 'sending_encryption', 'smtp_authentication', 'internal_notes', '_wysihtml5_mode');

            $this->emailService($driver, $service_request);
            $this->setMailConfig($driver, $username, $name, $password, $enc, $host, $port);
            $controller = new \App\Http\Controllers\Common\PhpMailController();
            $to = 'example@ladybirdweb.com';
            $toname = 'test';
            $subject = 'test';
            $data = 'test';
            //dd(\Config::get('mail'),\Config::get('services'));
            $send = $controller->laravelMail($to, $toname, $subject, $data, [], []);
        } catch (Exception $e) {
            \Log::error($e->getMessage());
            //dd($e);
        }

        return $send;
    }

    public function setMailConfig($driver, $username, $name, $password, $enc, $host, $port)
    {
        $configs = [
            'username'   => $username,
            'from'       => ['address' => $username, 'name' => $name],
            'password'   => $password,
            'encryption' => $enc,
            'host'       => $host,
            'port'       => $port,
            'driver'     => $driver,
        ];
        foreach ($configs as $key => $config) {
            if (is_array($config)) {
                foreach ($config as $from) {
                    \Config::set('mail.'.$key, $config);
                }
            } else {
                \Config::set('mail.'.$key, $config);
            }
        }
    }

    public function getDriver($driver_id)
    {
        $short = '';
        $email_drivers = new \App\Model\MailJob\MailService();
        $email_driver = $email_drivers->find($driver_id);
        if ($email_driver) {
            $short = $email_driver->short_name;
        }

        return $short;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param type Emails        $email
     * @param type EmailsRequest $request
     *
     * @return type Redirect
     */
    public function store($request, $imap_check, $service_request = [], $id = '')
    {
        $email = new Emails();
        if ($id !== '') {
            $email = $email->find($id);
        }
        try {
            $email->email_address = $request->email_address;

            $email->email_name = $request->email_name;
            $email->fetching_host = $request->fetching_host;
            $email->fetching_port = $request->fetching_port;
            $email->fetching_protocol = $request->fetching_protocol;
            $email->sending_host = $request->sending_host;
            $email->sending_port = $request->sending_port;
            $email->sending_protocol = $this->getDriver($request->sending_protocol);
            $email->sending_encryption = $request->sending_encryption;

            if ($request->smtp_validate == 'on') {
                $email->smtp_validate = $request->smtp_validate;
            }

            if ($request->input('password')) {
                $email->password = Crypt::encrypt($request->input('password'));
            }
            if ($request->input('fetching_status') == 'on') {
                $email->fetching_status = 1;
            } else {
                $email->fetching_status = 0;
            }
            if ($request->input('sending_status') == 'on') {
                $email->sending_status = 1;
            } else {
                $email->sending_status = 0;
            }
            if ($request->input('auto_response') == 'on') {
                $email->auto_response = 1;
            } else {
                $email->auto_response = 0;
            }
            //dd($email);
            if ($imap_check !== null) {
                $email->fetching_encryption = $imap_check[0];
            } else {
                $email->fetching_encryption = $request->input('fetching_encryption');
            }

            // fetching department value
            $email->department = $this->departmentValue($request->input('department'));
            // fetching priority value
            $email->priority = $this->priorityValue($request->input('priority'));
            // fetching helptopic value
            $email->help_topic = $this->helpTopicValue($request->input('help_topic'));
            // inserting the encrypted value of password
            $email->password = Crypt::encrypt($request->input('password'));
            $email->save(); // run save
            if ($id === '') {
                // Creating a default system email as the first email is inserted to the system
                $email_settings = Email::where('id', '=', '1')->first();
                $email_settings->sys_email = $email->id;
                $email_settings->save();
            } else {
                $this->update($id, $request);
            }
            if (count($service_request) > 0) {
                $this->saveMailService($email->id, $service_request, $this->getDriver($request->sending_protocol));
            }

            return 1;
        } catch (Exception $e) {
            return 0;
        }
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param type int             $id
     * @param type Department      $department
     * @param type Help_topic      $help
     * @param type Emails          $email
     * @param type Priority        $priority
     * @param type MailboxProtocol $mailbox_protocol
     *
     * @return type Response
     */
    public function edit($id, Department $department, Help_topic $help, Emails $email, Ticket_Priority $ticket_priority, MailboxProtocol $mailbox_protocol)
    {
        try {
            $sys_email = \DB::table('settings_email')->select('sys_email')->where('id', '=', 1)->first();
            // dd($sys_email);
            // fetch the selected emails
            $emails = $email->whereId($id)->first();
            // get all the departments
            $departments = $department->get();
            //get count of emails
            $count = $email->count();
            // get all the helptopic
            $helps = $help->get();
            // get all the priority
            $priority = $ticket_priority->get();
            // get all the mailbox protocols
            $mailbox_protocols = $mailbox_protocol->get();

            $service = new \App\Model\MailJob\MailService();
            $services = $service->lists('name', 'id')->toArray();

            // return if the execution is succeeded
            return view('themes.default1.admin.helpdesk.emails.emails.edit', compact('mailbox_protocols', 'priority', 'departments', 'helps', 'emails', 'sys_email', 'services'))->with('count', $count);
        } catch (Exception $e) {
            // return if try fails
            return redirect()->back()->with('fails', $e->getMessage());
        }
    }

    /**
     * Check for email input validation.
     *
     * @param EmailsRequest $request
     *
     * @return int
     */
    public function validatingEmailSettingsUpdate($id, MailRequest $request)
    {
        return $this->validatingEmailSettings($request, $id);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param type                   $id
     * @param type Emails            $email
     * @param type EmailsEditRequest $request
     *
     * @return type Response
     */
    public function update($id, $request)
    {
        try {
            if ($request->sys_email == 'on') {
                $system = \DB::table('settings_email')
                        ->where('id', '=', 1)
                        ->update(['sys_email' => $id]);
            } elseif ($request->input('count') <= 1 && $request->sys_email == null) {
                $system = \DB::table('settings_email')
                        ->where('id', '=', 1)
                        ->update(['sys_email' => null]);
            }
            $return = 1;
        } catch (Exception $e) {
            $return = $e->getMessage();
        }

        return $return;
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param type int    $id
     * @param type Emails $email
     *
     * @return type Redirect
     */
    public function destroy($id, Emails $email)
    {
        // fetching the details on the basis of the $id passed to the function
        $default_system_email = Email::where('id', '=', '1')->first();
        if ($default_system_email->sys_email) {
            // checking if the default system email is the passed email
            if ($id == $default_system_email->sys_email) {
                return redirect('emails')->with('fails', Lang::get('lang.you_cannot_delete_system_default_email'));
            }
        }
        try {
            // fetching the database instance of the current email
            $emails = $email->whereId($id)->first();
            // checking if deleting the email is success or if it's carrying any dependencies
            if ($emails->delete() == true) {
                return redirect('emails')->with('success', Lang::get('lang.email_deleted_sucessfully'));
            } else {
                return redirect('emails')->with('fails', Lang::get('lang.email_can_not_delete'));
            }
        } catch (Exception $e) {
            // returns if the try fails
            return redirect()->back()->with('fails', $e->getMessage());
        }
    }

    /**
     * Create imap connection.
     *
     * @param type $request
     *
     * @return type int
     */
    public function getImapStream($request, $validate)
    {
        $fetching_status = $request->input('fetching_status');
        $username = $request->input('email_address');
        $password = $request->input('password');
        $protocol_id = $request->input('mailbox_protocol');
        $fetching_protocol = '/'.$request->input('fetching_protocol');
        $fetching_encryption = '/'.$request->input('fetching_encryption');
        if ($fetching_encryption == '/none') {
            $fetching_encryption2 = '/novalidate-cert';
            $mailbox_protocol = $fetching_encryption2;
            $host = $request->input('fetching_host');
            $port = $request->input('fetching_port');
            $mailbox = '{'.$host.':'.$port.$fetching_protocol.$mailbox_protocol.'}INBOX';
        } else {
            $mailbox_protocol = $fetching_protocol.$fetching_encryption;
            $host = $request->input('fetching_host');
            $port = $request->input('fetching_port');
            $mailbox = '{'.$host.':'.$port.$mailbox_protocol.$validate.'}INBOX';
            $mailbox_protocol = $fetching_encryption.$validate;
        }
        try {
            $imap_stream = imap_open($mailbox, $username, $password);
        } catch (\Exception $ex) {
            \Log::error($ex->getMessage());

            return $ex->getMessage();
        }
        //$imap_stream = imap_open($mailbox, $username, $password);
        if ($imap_stream) {
            $return = [0 => 1, 1 => $mailbox_protocol];
        } else {
            $return = [0 => 0];
        }

        return $return;
    }

    /**
     * Check connection.
     *
     * @param type $imap_stream
     *
     * @return type int
     */
    public function checkImapStream($imap_stream)
    {
        $check_imap_stream = imap_check($imap_stream);
        if ($check_imap_stream) {
            $imap_stream = 1;
        } else {
            $imap_stream = 0;
        }

        return $imap_stream;
    }

    /**
     * Get smtp connection.
     *
     * @param type $request
     *
     * @return int
     */
    public function getSmtp($request)
    {
        $sending_status = $request->input('sending_status');
        // cheking for the sending protocol
        if ($request->input('sending_protocol') == 'smtp') {
            $mail = new \PHPMailer();
            $mail->isSMTP();
            $mail->Host = $request->input('sending_host');            // Specify main and backup SMTP servers
            //$mail->SMTPAuth = true;                                   // Enable SMTP authentication
            $mail->Username = $request->input('email_address');       // SMTP username
            $mail->Password = $request->input('password');            // SMTP password
            $mail->SMTPSecure = $request->input('sending_encryption'); // Enable TLS encryption, `ssl` also accepted
            $mail->Port = $request->input('sending_port');            // TCP port to connect to
            if (!$request->input('smtp_validate')) {
                $mail->SMTPAuth = true;                               // Enable SMTP authentication
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ],
                ];
                if ($mail->smtpConnect($mail->SMTPOptions) == true) {
                    $mail->smtpClose();
                    $return = 1;
                } else {
                    $return = 0;
                }
            } else {
                if ($mail->smtpConnect()) {
                    $mail->smtpClose();
                    $return = 1;
                } else {
                    $return = 0;
                }
            }
        } elseif ($request->input('sending_protocol') == 'mail') {
            $return = 1;
        }

        return $return;
    }

    /**
     * Checking if department value is null.
     *
     * @param type $dept
     *
     * @return type string or null
     */
    public function departmentValue($dept)
    {
        if ($dept) {
            $email_department = $dept;
        } else {
            $email_department = null;
        }

        return $email_department;
    }

    /**
     * Checking if priority value is null.
     *
     * @param type $priority
     *
     * @return type string or null
     */
    public function priorityValue($priority)
    {
        if ($priority) {
            $email_priority = $priority;
        } else {
            $email_priority = null;
        }

        return $email_priority;
    }

    /**
     * Checking if helptopic value is null.
     *
     * @param type $help_topic
     *
     * @return type string or null
     */
    public function helpTopicValue($help_topic)
    {
        if ($help_topic) {
            $email_help_topic = $help_topic;
        } else {
            $email_help_topic = null;
        }

        return $email_help_topic;
    }

    public function emailService($service, $value = [])
    {
        switch ($service) {
            case 'mailgun':
                $this->setServiceConfig($service, $value);
            case 'mandrill':
                $this->setServiceConfig($service, $value);
            case 'ses':
                $this->setServiceConfig($service, $value);
        }
    }

    public function setServiceConfig($service, $value)
    {
        //dd($service);
        if (count($value) > 0) {
            foreach ($value as $k => $v) {
                \Config::set("services.$service.$k", $v);
            }
        }
    }

    public function jsonResponse($out, $in)
    {
        if ($out !== 1) {
            $result = ['fails' => Lang::get('lang.outgoing_email_connection_failed')];
        }
        if ($in[0] !== 1) {
            $result = ['fails' => Lang::get('lang.incoming_email_connection_failed_please_check_email_credentials_or_imap_settings')];
        }
        if ($out === 1 && $in[0] === 1) {
            $result = ['success' => Lang::get('lang.success')];
        }

        return response()->json(compact('result'));
    }

    public function saveMailService($emailid, $request, $driver)
    {
        $mail_service = new \App\Model\MailJob\FaveoMail();
        $mails = $mail_service->where('email_id', $emailid)->get();
        if (count($request) > 0) {
            foreach ($mails as $mail) {
                $mail->delete();
            }
            foreach ($request as $key => $value) {
                $mail_service->create([
                    'drive'    => $driver,
                    'key'      => $key,
                    'value'    => $value,
                    'email_id' => $emailid,
                ]);
            }
        }
    }
}
