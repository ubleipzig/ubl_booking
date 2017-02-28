<?php

namespace LeipzigUniversityLibrary\UblBooking\Domain\Model;

use \LeipzigUniversityLibrary\UblBooking\Domain\Repository\OpeningHours;
use \LeipzigUniversityLibrary\UblBooking\Library\AbstractEntity;
use \LeipzigUniversityLibrary\UblBooking\Library\Week;
use \LeipzigUniversityLibrary\UblBooking\Library\Day;
use \LeipzigUniversityLibrary\UblBooking\Library\Hour;

class Room extends AbstractEntity {

	/**
	 * the week representation of the room
	 *
	 * @var \LeipzigUniversityLibrary\UblBooking\Library\Week
	 */
	protected $week;

	/**
	 * the day representation of the room
	 *
	 * @var \LeipzigUniversityLibrary\UblBooking\Library\Day
	 */
	protected $day;

	/**
	 * duty hours resolved as array
	 *
	 * @var array
	 */
	protected $openingHours = [];

	/**
	 * in the past
	 */
	const PAST = 1;

	/**
	 * not bookable at all
	 */
	const OFFDUTY = 2;

	/**
	 * available for booking
	 */
	const AVAILABLE = 4;

	/**
	 * already booked by someone else
	 */
	const FOREIGNBOOKED = 8;

	/**
	 * already booked by himself
	 */
	const OWNBOOKED = 16;

	/**
	 * @var string
	 **/
	protected $name = '';

	/**
	 * @var string
	 **/
	protected $description = '';

	/**
	 * where the opening times and closing days for the room are stored
	 * if empty take the plugins configured storage
	 *
	 * @var string
	 */
	protected $openingTimesStorage;

	/**
	 * where the bookings are stored. if empty take the plugins configured storage
	 *
	 * @var int
	 */
	protected $bookingStorage;
	/**
	 * $closingDayRepository
	 *
	 * @var \LeipzigUniversityLibrary\UblBooking\Domain\Repository\ClosingDay
	 * @inject
	 */
	protected $closingDayRepository;

	/**
	 * $bookingRepository
	 *
	 * @var \LeipzigUniversityLibrary\UblBooking\Domain\Repository\Booking
	 * @inject
	 */
	protected $bookingRepository;

	/**
	 * $openingHoursRepository
	 *
	 * @var \LeipzigUniversityLibrary\UblBooking\Domain\Repository\OpeningHours
	 * @inject
	 */
	protected $openingHoursRepository;

	/**
	 * a rooms bookings
	 *
	 * @var \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\LeipzigUniversityLibrary\UblBooking\Domain\Model\Booking>
	 * @lazy
	 * @cascade remove
	 */
	protected $bookings;

	/**
	 * a rooms closing days
	 *
	 * @var \TYPO3\CMS\Extbase\Persistence\ObjectStorage<\LeipzigUniversityLibrary\UblBooking\Domain\Model\ClosingDay>
	 * @lazy
	 */
	protected $closingDays;

	public function __construct() {
		echo 'foobar';
	}

	/**
	 * we are initializing the storage objects here since the constructor is not invoked for model when created by
	 * repositorys, god knows why
	 */
	public function initializeObject() {
		$this->bookings = new \TYPO3\CMS\Extbase\Persistence\ObjectStorage();
		$this->closingDays = new \TYPO3\CMS\Extbase\Persistence\ObjectStorage();
		return parent::initializeObject();
	}

	/**
	 * Adds a booking
	 *
	 * @param \LeipzigUniversityLibrary\UblBooking\Domain\Model\Booking $booking
	 * @return void
	 */
	public function addBooking(Booking $booking) {
		$this->bookings->attach($booking);
	}

	/**
	 * Removes a booking
	 *
	 * @param \LeipzigUniversityLibrary\UblBooking\Domain\Model\Booking $booking
	 * @return void
	 */
	public function removeBooking(Booking $booking) {
		$this->bookings->detach($booking);
	}

	/**
	 * Adds a closing day
	 *
	 * @param \LeipzigUniversityLibrary\UblBooking\Domain\Model\ClosingDay $closingDay
	 * @return void
	 */
	public function addClosingDay(ClosingDay $closingDay) {
		$this->closingDays->attach($closingDay);
	}

	/**
	 * Removes a closing day
	 *
	 * @param \LeipzigUniversityLibrary\UblBooking\Domain\Model\ClosingDay $closingDay
	 * @return void
	 */
	public function removeClosingDay(ClosingDay $closingDay) {
		$this->closingDays->detach($closingDay);
	}

	public function fetchWeekOccupation($week) {
		$this->setOpeningHours($this->openingHoursRepository->findAllByRoom($this));

		list($dayStart, $dayEnd) = $this->getMinMaxOpeningHours();
		$this->week = $week;
		$this->week->setDayStart($dayStart);
		$this->week->setDayEnd($dayEnd);

		foreach ($this->closingDayRepository->findByRoomAndBetween($this, $this->week->getStart(), $this->week->getEnd()) as $closingDay) {
			$this->addClosingDay($closingDay);
		}

		foreach ($this->bookingRepository->findByRoomAndBetween($this, $this->week->getStart(), $this->week->getEnd()) as $booking) {
			$this->addBooking($booking);
		}
	}

	public function fetchDayOccupation($day) {
		$this->day = $day;

		$this->setOpeningHours($this->openingHoursRepository->findByRoomAndDay($this, $this->day->getDateTime()));

		list($dayStart, $dayEnd) = $this->getMinMaxOpeningHours($this->day);

		$this->day->setStart($dayStart);
		$this->day->setEnd($dayEnd);

		if ($closingDay = $this->closingDayRepository->findByRoomAndDay($this, $this->day->getDateTime())) $this->addClosingDay($closingDay);

		foreach ($this->bookingRepository->findByRoomAndBetween($this, $this->day->getStart(), $this->day->getEnd()) as $booking) {
			$this->addBooking($booking);
		}
	}

	public function getHourOccupation(Hour $hour) {
		if (!$this->isDutyHour($hour)) return self::OFFDUTY;

		$day = new Day($hour->getTimestamp());

		if ($this->getDayOccupation($day) === self::OFFDUTY) {
			return self::OFFDUTY;
		}

		if ($booking = $this->findBooking($hour)) {
			if ($booking->getFeUser() === $GLOBALS['TSFE']->fe_user->user['uid']) return self::OWNBOOKED;
			if ($this->settingsHelper->isAdmin($booking->getFeUser())) return self::OFFDUTY;
			return self::FOREIGNBOOKED;
		};

		return self::AVAILABLE;
	}

	public function findBooking(Hour $hour) {
		foreach ($this->bookings as $booking) {
			if ($booking->getDateTime() == $hour->getDateTime()) {
				return $booking;
			}
		}
	}

	public function getDayOccupation(Day $day) {
		foreach ($this->closingDays as $closingDay) {
			if ($closingDay->getDay()->getTimestamp() === $day->getTimestamp()) return self::OFFDUTY;
		}

		return self::AVAILABLE;
	}

	public function isDayBookable(Day $day) {
		$now = new Day();
		if ($day->getDateTime() < $now->getDateTime()) {
			return false;
		}

		if ($this->getDayOccupation($day) === self::OFFDUTY) return false;

		if (!$GLOBALS['TSFE']->fe_user->user) return false;

		return true;
	}

	/**
	 * @param \LeipzigUniversityLibrary\UblBooking\Library\Hour $hour
	 * @return bool
	 */
	public function isHourBookable(Hour $hour) {
		if (!$this->isDayBookable(new Day($hour->getTimestamp()))) return false;
		$now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Berlin'));
		if ($hour->getDateTime() < $now) {
			return false;
		}
		$occupation = $this->getHourOccupation($hour);

		if (in_array($occupation, [self::OFFDUTY, self::FOREIGNBOOKED])) return false;

		if (!$GLOBALS['TSFE']->fe_user->user) return false;

		return true;
	}

	public function setOpeningHours($queryResponse) {
		foreach($queryResponse as $openingHours) {
			$this->openingHours[$openingHours->getWeekDay()] = explode(',', $openingHours->getHours());
		}
	}

	public function getMinMaxOpeningHours(Day $day = null) {
		// not all days defined? at least one day has 24h opening times
		if (!$day && count($this->openingHours) < 7) return [0,23];

		foreach($this->openingHours as $dayOfWeek => $openingHours) {
			if ($day && ((int)$day->format('N') !== $dayOfWeek)) continue;
			$min = min($openingHours);
			$max = max($openingHours);
			if (!isset($start) || $min < $start) $start = $min;
			if (!isset($end) || $max > $end) $end = $max;
		}

		if (!isset($start) && !isset($end)) {
			$start = 0;
			$end = 23;
		}

		return [$start, $end];
	}

	public function isDutyHour(Hour $hour) {
		$day = $hour->format('N');
		return isset($this->openingHours[$day]) ? in_array($hour->format('H'), $this->openingHours[$day]) : true;
	}

	public function getBooking($timestamp) {
		foreach ($this->bookings as $booking) {
			if ($booking->getDateTime() == $timestamp) return $booking;
		}
	}

	public function getOpeningTimesStorage() {
		return array_reduce(explode(',', $this->openingTimesStorage), function($carry, $item) {
			if (!empty($item)) $carry[] = (int)$item;
			return $carry;
		}, []);
	}

	public function setOpeningTimesStorage(array $value) {
		$this->openingTimesStorage = implode(',', $value);
	}
}