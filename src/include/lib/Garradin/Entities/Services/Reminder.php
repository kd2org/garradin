<?php

namespace Garradin\Entities\Services;

use Garradin\DynamicList;
use Garradin\Entity;
use Garradin\ValidationException;
use Garradin\Config;
use KD2\DB\EntityManager;

class Reminder extends Entity
{
	const TABLE = 'services_reminders';

	protected $id;
	protected $id_service;
	protected $delay;
	protected $subject;
	protected $body;

	protected $_types = [
		'id'         => 'int',
		'id_service' => 'int',
		'delay'      => 'int',
		'subject'    => 'string',
		'body'       => 'string',
	];

	public function selfCheck(): void
	{
		parent::selfCheck();
		$this->assert($this->id_service, 'Aucun service n\'a été indiqué pour ce tarif.');
		$this->assert(trim($this->subject) !== '', 'Le sujet doit être renseigné');
		$this->assert(strlen($this->subject) <= 200, 'Le sujet doit faire moins de 200 caractères');
		$this->assert(trim($this->body) !== '', 'Le corps du message doit être renseigné');
		$this->assert(strlen($this->body) <= 64000, 'Le corps du message doit faire moins de 64.000 caractères');
		$this->assert($this->delay !== null, 'Le délai de rappel doit être renseigné');
	}

	public function service()
	{
		return EntityManager::findOneById(Service::class, $this->id_service);
	}

	public function importForm(array $source = null)
	{
		if (null === $source) {
			$source = $_POST;
		}


		if (isset($source['delay_type'])) {
			if (1 == $source['delay_type'] && !empty($source['delay_before'])) {
				$source['delay'] = (int)$source['delay_before'] * -1;
			}
			elseif (2 == $source['delay_type'] && !empty($source['delay_after'])) {
				$source['delay'] = (int)$source['delay_after'];
			}
			else {
				$source['delay'] = 0;
			}
		}

		parent::importForm($source);
	}

	public function sentList(): DynamicList
	{
		$identity = Config::getInstance()->get('champ_identite');
		$columns = [
			'id_user' => [
				'select' => 'srs.id_user',
			],
			'identity' => [
				'label' => 'Membre',
				'select' => 'm.' . $identity,
			],
			'email' => [
				'label' => 'Adresse e-mail',
				'select' => 'm.email',
			],
			'date' => [
				'label' => 'Date d\'envoi',
				'select' => 'srs.sent_date',
				'order' => 'srs.sent_date %s, srs.id %1$s',
			],
		];

		$tables = 'services_reminders_sent srs
			INNER JOIN membres m ON m.id = srs.id_user';
		$conditions = sprintf('srs.id_reminder = %d', $this->id());

		$list = new DynamicList($columns, $tables, $conditions);
		$list->orderBy('date', true);
		return $list;
	}

}
