<?php
namespace Clevis\LogAnalyzer;

use Nette;
use Nette\Application\UI;
use Clevis\Skeleton\Core;


class LogAnalyzerPresenter extends Core\BasePresenter
{

	/** @var \Clevis\LogAnalyzerService @inject */
	public $logAnalyzerService;


	public function renderDefault($startDate = NULL, $endDate = NULL, $onlyActive = TRUE, $orderBy = NULL)
	{
		if ($startDate !== NULL) $startDate = Nette\DateTime::from($startDate);
		if ($endDate !== NULL) $endDate = Nette\DateTime::from($endDate);

		$errors = $this->logAnalyzerService->getErrors($startDate, $endDate, $onlyActive, $orderBy);

		$this['filterForm']->setDefaults(array(
			'startDate' => $startDate,
			'endDate' => $endDate,
			'onlyActive' => $onlyActive,
		));

		$this->template->data = $errors;
	}

	public function createComponentCommentForm()
	{
		$form = new Ui\Form;

		$form->addProtection();
		$form->addHidden('errorId');
		$form->addText('newComment', 'Přidat komentář')
			->setAttribute('placeholder', 'Přidat komentář')
			->setRequired('Zadejte komenář');

		$form->addSubmit('send');

		$form->onSuccess[] = [$this, 'commentFormSubmitted'];

		return $form;
	}

	public function commentFormSubmitted(UI\Form $form)
	{
		$values = $form->getValues();

		$error = $this->logAnalyzerService->getError($values->errorId);
		if (!$error) $this->error();

		$now = new \DateTime;
		$error->comments .= $now->format("Y-m-d H:i:s - ") . $values->newComment . (isset($this->user) ? ' (#' . $this->user->id . ')':'') . PHP_EOL;

		$this->logAnalyzerService->saveComment($error);

		$this->redirect('this');
	}


	public function handleGetUrls($errorId)
	{
		$urls = $this->logAnalyzerService->getUrls($errorId, 100);
		$this->payload->urls = $urls;
		$this->sendPayload();
	}

	/**
	 * @secured
	 */
	public function handleMarkAsResolved($errorId)
	{
		$this->logAnalyzerService->markAsResolved($errorId);

		if ($this->isAjax())
		{
			$this->sendPayload();
		}
		else
		{
			$this->flashMessage('Problém byl označen jako vyřešený.');
			$this->redirect('this');
		}
	}

	/**
	 * @secured
	 */
	public function handleMarkAsReopened($errorId)
	{
		$this->logAnalyzerService->markAsReopened($errorId);

		if ($this->isAjax())
		{
			$this->sendPayload();
		}
		else
		{
			$this->flashMessage('Problém byl označen jako znovuotevřený.');
			$this->redirect('this');
		}
	}

	protected function createComponentFilterForm()
	{
		$form = new UI\Form();
		$form->addDatePicker('startDate');
		$form->addDatePicker('endDate');
		$form->addCheckbox('onlyActive')
			->setDefaultValue(TRUE);
		$form->addSubmit('send');

		$form->onSuccess[] = [$this, 'filterFormSubmitted'];

		return $form;
	}

	public function filterFormSubmitted(UI\Form $form)
	{
		$startDate = $endDate = NULL;
		$values = $form->getValues();
		if ($values['startDate'] !== NULL) $startDate = $values['startDate']->format('Y-m-d');
		if ($values['endDate'] !== NULL) $endDate = $values['endDate']->format('Y-m-d');

		$this->redirect('default', array(
			'startDate' => $startDate,
			'endDate' => $endDate,
			'onlyActive' => $values['onlyActive'],
		));
	}

	public function renderViewException($hash)
	{
		if (strpos($hash, '/') !== FALSE || strpos($hash, '..') !== FALSE) $this->error();

		try
		{
			$content = $this->logAnalyzerService->getRedscreenContent($hash);
			$response = new Nette\Application\Responses\TextResponse($content);
			$this->sendResponse($response);
		}
		catch (FileNotFoundException $e)
		{
			$this->error();
		}
	}

}
