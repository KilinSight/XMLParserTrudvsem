<?php

namespace AppBundle\Controller;

use AppBundle\Entity\TelegramMessage;
use AppBundle\Entity\TelegramUser;
use AppBundle\Entity\UnresolvedCommand;
use AppBundle\Entity\UpdateMetadataDto;
use AppBundle\Manager\TelegramManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SwiftmailerBundle\Command\SendEmailCommand;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use function Doctrine\ORM\QueryBuilder;

class DefaultController extends Controller
{
    const BOT_API_SET_WEBHOOK = 'setWebhook';
    const BOT_API_GET_WEBHOOK_INFO = 'getWebhookInfo';
    const BOT_API_GET_UPDATES = 'getUpdates';
    const BOT_API_SEND_MESSAGE = 'sendMessage';
    const TEST_UPDATE_BODY = 'testUpdateBody';

    /**
     * @Route("/get_csv", name="get_csv")
     * @param Request $request
     * @return mixed
     */
    public function getRunDataAction(Request $request)
    {

        $params = http_build_query(array(
            "api_key" => ApiController::apikey,
            "format" => "csv"
        ));

        $result = file_get_contents(
            'https://www.parsehub.com/api/v2/runs/' . ApiController::PARSEHUB_RUN_TOKEN . '/data?' . $params,
            false,
            stream_context_create(array(
                'http' => array(
                    'method' => 'GET'
                )
            ))
        );
        file_put_contents('C:/csv/test.csv', $result);

        return new Response($result);
    }



    /**
     * @Route("/bot/make_request/{method}", name="make_request", options = {"expose" : true})
     *
     * @param string $method
     *
     * @param Request $request
     * @return mixed
     * @throws \Exception
     */
    public function makeRequestAction($method, ?Request $request)
    {
        $telegramManager = $this->get(TelegramManager::class);
        $apiUrl = 'https://api.telegram.org/bot' . ApiController::botapikey . '/' . $method;
        $allowedMethods = [
            self::BOT_API_SEND_MESSAGE,
            self::BOT_API_GET_WEBHOOK_INFO,
            self::TEST_UPDATE_BODY,
        ];

        if (!in_array($method, $allowedMethods)) {
            return new JsonResponse('Method not allowed.');
        }
        $body = $request->get('body');
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $apiUrl);

        if ($method === self::BOT_API_SEND_MESSAGE) {
            curl_setopt($curl, CURLOPT_POST, 1);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }elseif($method === self::TEST_UPDATE_BODY){
            $update = $telegramManager->getUpdateMetadata($body);
            dump($update);
            die;
        }

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

        $output = curl_exec($curl);

        if ($output === false) {
            $telegramManager->throwException(curl_error($curl));
        }

        curl_close($curl);

        return new Response(Response::HTTP_OK);
    }

    /**
     * @Route("/saxapok_webhook", name="saxapok_webhook")
     * @param Request $request
     * @return mixed
     * @throws \Exception
     */
    public function webhookAction(Request $request)
    {
        /** @var EntityManagerInterface $em */
        $em = $this->getDoctrine()->getManager();
        $telegramManager = $this->get(TelegramManager::class);
        $updateRaw = $telegramManager->getUpdateRaw();
        $update = $telegramManager->getUpdateMetadata($updateRaw);
//        $telegramManager->notifyAdmins(json_encode($updateRaw));
        if($update->getDate()->getTimestamp()){
//            if(!$update->isForwarded() && !$update->getUser()->getIsBot()){
//                $telegramManager->forwardToAdmin($update->getUser()->getUserId(), $update->getMessageId());
//            }
            $userFromUpdate = $update->getUser();
            $userToUpdate = $telegramManager->getUserByUserId($update->getChatId());
            $userAdmin = $telegramManager->getAdminUser();
            $userBot = $telegramManager->getBotUser();

            $tgFromMessage = $telegramManager->getOrCreateMessage($update->getMessageId());
            $tgFromMessage->setChat($userToUpdate);
            $tgFromMessage->setFrom($userFromUpdate);
            $tgFromMessage->setDate($update->getDate());
            $tgFromMessage->setText($update->getMessageText());

            if($update->getType() === UpdateMetadataDto::TYPE_COMMAND){
                list($command, $targetId) = explode('_', $update->getCommand());
                if($command === UnresolvedCommand::COMMAND_REPLY){
                    if($telegramManager->validateUserCommand($userToUpdate, $command)){
                        $targetUser = $telegramManager->getUserByUserId($targetId);
                        if($targetUser){
                            $parameters = ['reply_to' => $targetUser->getUserId()];
                            $telegramManager->createUnresolvedCommandByUser($userAdmin, $command, $parameters);
                            $tgToMessage = new TelegramMessage(null, $update->getMessageId(), $userBot, $userAdmin, $update->getDate(), "Введите текст ответа пользователю @" . $targetUser->getUsername());
                            $telegramManager->sendMessageTo($tgToMessage);
                        }
                    }
                }elseif($command === UnresolvedCommand::COMMAND_CANCEL){
                    $tgFromMessage->setStatus(UnresolvedCommand::COMMAND_CANCEL);
                }

                $telegramManager->saveMessageToDB($tgFromMessage);
            }elseif($tgFromMessage->getText()){
                if(!$update->getUser()->getIsBot()){

                    foreach ($userFromUpdate->getUnresolvedCommands() as $unresolvedCommand) {
                        if($unresolvedCommand->getDate() < time() - 30){
                            if($unresolvedCommand->getCommand() !== UnresolvedCommand::COMMAND_DEBUG){
                                $em->remove($unresolvedCommand);
                            }
                            continue;
                        }
                        if($unresolvedCommand->getCommand() === UnresolvedCommand::COMMAND_REPLY){
                            $parameters = json_decode($unresolvedCommand->getParameters(), true);
                            $replyToUser = $telegramManager->getUserByUserId($parameters['reply_to']);
                            $replyMessage = new TelegramMessage(null, $update->getMessageId(), $userBot, $replyToUser, $update->getDate(), $tgFromMessage->getText());
                            $telegramManager->sendMessageTo($replyMessage);
                            $em->remove($unresolvedCommand);
                        }elseif($unresolvedCommand->getCommand() === UnresolvedCommand::COMMAND_DEBUG){
                            $debugMessage = new TelegramMessage(null, $update->getMessageId(), $userBot, $userAdmin, $update->getDate(), json_encode($updateRaw));
                            $telegramManager->sendMessageTo($debugMessage);
                        }
                    }

                    $lastCommand = $telegramManager->getLastCommandFromMessage($tgFromMessage);
                    $telegramManager->notifyAdmins($lastCommand . ' ssssss ' . $tgFromMessage->getText());

                    if($lastCommand){
                        $telegramManager->notifyAdmins($lastCommand);
                        if($lastCommand === UnresolvedCommand::COMMAND_DEBUG){
                            $issetDebug = $em->getRepository(UnresolvedCommand::class)->findOneBy(['command' => UnresolvedCommand::COMMAND_DEBUG]);
                            if(!$issetDebug){
                                $telegramManager->createUnresolvedCommandByUser($userAdmin, UnresolvedCommand::COMMAND_DEBUG, []);

                                $notifyMessage = new TelegramMessage(null, $update->getMessageId(), $userBot, $replyToUser, $update->getDate(), 'Debug mode ON');
                                $telegramManager->sendMessageTo($notifyMessage);
                            }else{
                                $notifyMessage = new TelegramMessage(null, $update->getMessageId(), $userBot, $replyToUser, $update->getDate(), 'Debug mode already inited');
                                $telegramManager->sendMessageTo($notifyMessage);
                            }

                        }elseif($lastCommand === UnresolvedCommand::COMMAND_STOP){
                            $telegramManager->deleteAllUnresolvedCommandByUser($userFromUpdate);

                            $notifyMessage = new TelegramMessage(null, $update->getMessageId(), $userBot, $replyToUser, $update->getDate(), 'All active commands was disabled');
                            $telegramManager->sendMessageTo($notifyMessage);
                        }
                    }

                    $tgFromMessage->setStatus(TelegramMessage::STATUS_SEEN);
                    $telegramManager->saveMessageToDB($tgFromMessage);
                }
            }
        }

        return new Response(null, Response::HTTP_OK, ["HTTP/1.1 200 OK"]);
    }

    /**
     * @Route("/", name="homepage")
     * @param Request $request
     * @return mixed
     */
    public function indexAction(Request $request)
    {
        $telegramManager = $this->get(TelegramManager::class);
        $telegramManager->getOrCreateParsedImage('test');

        return $this->render('saxapok/index.html.twig');
    }

    /**
     * @Route("/parse", name="parse")
     * @param Request $request
     * @return mixed
     */
    public function parseImagesAction(Request $request)
    {
        $params = array(
            "api_key" => ApiController::apikey,
            "start_url" => "http://pinterest.ru",
            "start_template" => "login",
            "start_value_override" => "",
            "send_email" => "1"
        );

        $options = array(
            'http' => array(
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded; charset=utf-8',
                'content' => http_build_query($params)
            )
        );

        $context = stream_context_create($options);
        $result = file_get_contents('https://www.parsehub.com/api/v2/projects/tRRO22Ex294T/run', false, $context);
        print_r($result);

        return new Response();
    }

    /**
     * @Route("/get_results", name="search_get_results", options = {"expose" : true})
     * @param Request $request
     * @return mixed
     */
    public function getResultsAction(Request $request)
    {
        $root = 'O:' . DIRECTORY_SEPARATOR . 'data';
        $filesList = scandir($root . DIRECTORY_SEPARATOR . 'csv/');
        foreach ($filesList as $file) {
            $imagesUrls[] = fgetcsv($file);
        }

        print_r($imagesUrls);


        return new Response();
    }
}
