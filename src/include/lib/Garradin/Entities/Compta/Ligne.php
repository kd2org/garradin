<?php

namespace Garradin\Entities\Compta;

use Garradin\Entity;
use Garradin\ValidationException;

class Ligne extends Entity
{
	const TABLE = 'compta_mouvements_lignes';

	protected $id;
	protected $id_mouvement;
	protected $credit = 0;
	protected $debit = 0;
	protected $compte;

	protected $_types = [
		'id_mouvement' => 'int',
		'credit'       => 'int',
		'debit'        => 'int',
		'compte'       => 'int',
	];

	protected $_validation_rules = [
		'id_mouvement' => 'required|integer|in_table:compta_mouvements,id',
		'compte'       => 'required|integer|in_table:compta_comptes,id',
		'credit'       => 'required|integer|min:0',
		'debit'        => 'required|integer|min:0'
	];

	public function filterUserValue(string $key, $value, array $source)
	{
		$value = parent::filterUserValue($key, $value);

		if ($key == 'credit' || $key == 'debit')
		{
			if (!preg_match('/^(\d+)(?:[,.](\d{2}))?$/', $value, $match))
			{
				throw new ValidationException('Le format du montant est invalide. Format accepté, exemple : 142,02');
			}

			$value = $match[1] . sprintf('%02d', $match[2]);
		}

		return $value;
	}

	public function selfCheck()
	{
		parent::selfCheck();
		$this->assert($this->credit || $this->debit, 'Aucun montant au débit ou au crédit.');
		$this->assert(($this->credit * $this->debit) === 0 && ($this->credit + $this->debit) > 0, 'Ligne non équilibrée : crédit ou débit doit valoir zéro.');
		$this->assert($this->id_mouvement, 'Aucun mouvement n\'a été indiqué pour cette ligne.');
	}
}