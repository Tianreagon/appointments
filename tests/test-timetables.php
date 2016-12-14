	<?php

/**
 * Class App_Appointments_Test
 *
 * @group timetables
 */
class App_Timetables_Test extends App_UnitTestCase {

	function test_timetables_cache() {
		global $appointments;

		// Insert a worker
		$args = $this->factory->user->generate_args();
		$user_id_1 = $this->factory->user->create_object( $args );

		$args = $this->factory->user->generate_args();
		$user_id_2 = $this->factory->user->create_object( $args );

		$service_id = appointments_insert_service( array( 'name' => 'My Service' ) );

		$args = array(
			'ID' => $user_id_1,
			'price' => '19.7',
			'services_provided' => array( $service_id ),
			'dummy' => true
		);
		$result = appointments_insert_worker( $args );

		$time = time();
		$date = date( 'Y-m-01', $time );
		$capacity = appointments_get_capacity();
		$date_start = strtotime("{$date} 00:00");
		$service = appointments_get_service($service_id);
		$min_step_time = $appointments->get_min_time() * 60;
		$step = (!empty($service->duration) ? $service->duration : $min_step_time) * 60;
		$key = $date_start . '-' . $capacity . '-0' . '-' . $appointments->worker . '-' . date( 'Ym', $appointments->local_time ) . '-' . $step;



		// WORKERS

		// Trigger the timetables cache
		appointments_get_timetable( $date_start, $capacity );
		// This saves the timetables
		do_action( 'shutdown' );
		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertNotEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Insert another worker
		$args = array(
			'ID' => $user_id_2,
			'price' => '19.7',
			'services_provided' => array( $service_id ),
			'dummy' => true
		);
		appointments_insert_worker( $args );
		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Trigger the timetables cache
		appointments_get_timetable( $date_start, $capacity );

		// This saves the timetables
		do_action( 'shutdown' );

		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertNotEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Delete a worker
		appointments_delete_worker( $user_id_2 );
		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Trigger the timetables cache
		appointments_get_timetable( $date_start, $capacity );

		// This saves the timetables
		do_action( 'shutdown' );

		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertNotEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Update worker
		appointments_update_worker( $user_id_1, array( 'price' => '10' ) );
		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );


		// APPOINTMENTS

		// Trigger the timetables cache
		appointments_get_timetable( $date_start, $capacity );

		// This saves the timetables
		do_action( 'shutdown' );

		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertNotEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Insert appointment
		$args = array(
			'user' => $user_id_2,
			'email' => 'tester@tester.com',
			'name' => 'Tester',
			'phone' => '667788',
			'address' => 'An address',
			'city' => 'Madrid',
			'service' => $service_id,
			'worker' => $user_id_1,
			'price' => '90',
			'date' => 'December 18, 2024',
			'time' => '07:30',
			'note' => 'It\'s a note',
			'status' => 'paid',
			'location' => 5,
			'gcal_updated' => '2015-12-01',
			'gcal_ID' => 'test'
		);
		$app_id = appointments_insert_appointment( $args );

		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Trigger the timetables cache
		appointments_get_timetable( $date_start, $capacity );

		// This saves the timetables
		do_action( 'shutdown' );

		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertNotEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Update Appointment
		appointments_update_appointment( $app_id, array( 'address' => 'New address' ) );
		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Trigger the timetables cache
		appointments_get_timetable( $date_start, $capacity );

		// This saves the timetables
		do_action( 'shutdown' );

		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertNotEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Delete appointment
		appointments_delete_appointment( $app_id );
		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );


		// SERVICES

		// Insert a service

		// Trigger the timetables cache
		appointments_get_timetable( $date_start, $capacity );

		// This saves the timetables
		do_action( 'shutdown' );

		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertNotEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		$service_id_2 = appointments_insert_service( array( 'name' => 'My Service 2' ) );

		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Trigger the timetables cache
		appointments_get_timetable( $date_start, $capacity );

		// This saves the timetables
		do_action( 'shutdown' );

		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertNotEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Update service
		appointments_update_service( $service_id_2, array( 'name' => 'My Service updated' ) );
		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Trigger the timetables cache
		appointments_get_timetable( $date_start, $capacity );

		// This saves the timetables
		do_action( 'shutdown' );

		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertNotEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

		// Delete service
		appointments_delete_service( $service_id_2 );
		$timetables_cache = get_transient( 'app_timetables' );
		$this->assertEmpty( $timetables_cache[ $key . '-' . $appointments->service ] );

	}


	/**
	 * @group is-busy
	 */
	function test_is_busy() {
		$next_monday = strtotime( 'next monday', time() );

		$service_id = $this->factory->service->create_object($this->factory->service->generate_args());

		$worker_args = $this->factory->worker->generate_args();
		$worker_args['services_provided'] = array( $service_id );
		$worker_id = $this->factory->worker->create_object( $worker_args );

		$app_args           = $this->factory->appointment->generate_args();
		$app_args['status'] = 'reserved';
		$app_args['name'] = 'Holidays';
		$app_args['date']   = strtotime( date( 'Y-m-d', $next_monday ) . ' 12:00:00' );
		$app_args['duration'] = 60;
		$app_args['worker'] = $worker_id;
		$app_args['service'] = $service_id;
		$app_id = $this->factory->appointment->create_object( $app_args );

		$appointments = appointments();
		$appointments->worker = $worker_id;
		$appointments->service = $service_id;

		$from = strtotime( date( 'Y-m-d', $next_monday ) . ' 12:00:00' );
		$to = strtotime( date( 'Y-m-d', $next_monday ) . ' 12:15:00' );

		$busy = $appointments->is_busy( $from, $to, 1 );
		$this->assertTrue( $busy );

		$from = strtotime( date( 'Y-m-d', $next_monday ) . ' 10:00:00' );
		$to = strtotime( date( 'Y-m-d', $next_monday ) . ' 10:15:00' );

		$busy = $appointments->is_busy( $from, $to, 1 );
		$this->assertFalse( $busy );
	}

	/**
	 * @group temp
	 */
	function test_get_available_workers() {
		$options = appointments_get_options();
		$options['min_time'] = 60;
		appointments_update_options( $options );
		$service_id = $this->factory->service->create_object($this->factory->service->generate_args());
		$worker_args = $this->factory->worker->generate_args();
		$worker_args['services_provided'] = array( $service_id );
		$worker_id_1 = $this->factory->worker->create_object( $worker_args );

		$worker_args = $this->factory->worker->generate_args();
		$worker_args['services_provided'] = array( $service_id );
		$worker_id_2 = $this->factory->worker->create_object( $worker_args );

		// Let's put worker 1 on holiday on 2016-12-28
		appointments_update_worker_exceptions( $worker_id_1, 'closed', '2016-12-28' );

		// Appointment for worker 2 on 2016-12-28
		$app_id = appointments_insert_appointment(
			array(
				'service'  => $service_id,
				'worker' => $worker_id_2,
				'status'   => 'confirmed',
				'date'     => strtotime( '2016-12-28 11:00:00' ),
				'duration' => 480
			)
		);

		appointments()->service = $service_id;

		// Now there should not be any worker available on 2016-12-28 from 11:00 to 19:00
		$available = appointments()->available_workers(
			strtotime( '2016-12-28 11:00:00' ), // From
			strtotime( '2016-12-28 12:00:00' ) // To
		);

		// Though worker 2 has an appointment, this function does not check that
		$this->assertEquals( 1, $available );

		$available = appointments()->available_workers(
			strtotime( '2016-12-29 11:00:00' ), // From
			strtotime( '2016-12-29 12:00:00' ) // To
		);
		$this->assertEquals( 2, $available );

		// Now set a capacity for the service
		appointments_update_service( $service_id, array( 'capacity' => 1 ) );

		// Now it should return just one
		$available = appointments()->available_workers(
			strtotime( '2016-12-29 11:00:00' ), // From
			strtotime( '2016-12-29 12:00:00' ) // To
		);
		$this->assertEquals( 1, $available );
	}


	function test_is_break() {
		$service_id = $this->factory->service->create_object($this->factory->service->generate_args());
		$worker_args = $this->factory->worker->generate_args();
		$worker_args['services_provided'] = array( $service_id );
		$worker_id_1 = $this->factory->worker->create_object( $worker_args );

		$wh = appointments_get_worker_working_hours( 'closed', $worker_id_1 )->hours;
		$wh['Tuesday']['active'] = 'yes';
		$wh['Tuesday']['start'] = '10:00';
		$wh['Tuesday']['end'] = '18:00';
		appointments_update_worker_working_hours( $worker_id_1, $wh, 'closed' );

		$next_tuesday = date( 'Y-m-d', strtotime( 'next Tuesday' ) );
		$this->assertTrue(
			appointments()->is_break(
				strtotime( "$next_tuesday 11:00" ),
				strtotime( "$next_tuesday 12:00" ),
				$worker_id_1
			)
		);

		$this->assertFalse(
			appointments()->is_break(
				strtotime( "$next_tuesday 08:00" ),
				strtotime( "$next_tuesday 12:00" ),
				$worker_id_1
			)
		);

		$this->assertTrue(
			appointments()->is_break(
				strtotime( "$next_tuesday 10:00" ),
				strtotime( "$next_tuesday 18:00" ),
				$worker_id_1
			)
		);

		// Edge case
		$wh = appointments_get_worker_working_hours( 'closed', $worker_id_1 )->hours;
		$wh['Tuesday']['active'] = 'yes';
		$wh['Tuesday']['start'] = '00:00';
		$wh['Tuesday']['end'] = '00:00';
		appointments_update_worker_working_hours( $worker_id_1, $wh, 'closed' );

		$this->assertTrue(
			appointments()->is_break(
				strtotime( "$next_tuesday 00:00" ),
				strtotime( "$next_tuesday 23:59" ),
				$worker_id_1
			)
		);

		$this->assertTrue(
			appointments()->is_break(
				strtotime( "$next_tuesday 10:00" ),
				strtotime( "$next_tuesday 12:00" ),
				$worker_id_1
			)
		);
	}

	function test_undefined_service_should_be_busy_for_worker() {
//		$next_monday = strtotime( 'next monday', time() );
//
//		$service_id = $this->factory->service->create_object($this->factory->service->generate_args());
//
//		$worker_args = $this->factory->worker->generate_args();
//		$worker_args['services_provided'] = array( $service_id );
//		$worker_id = $this->factory->worker->create_object( $worker_args );
//
//		$app_args           = $this->factory->appointment->generate_args();
//		$app_args['status'] = 'reserved';
//		$app_args['name'] = 'Holidays';
//		$app_args['date']   = strtotime( date( 'Y-m-d', $next_monday ) . ' 12:00:00' );
//		$app_args['duration'] = 60;
//		$app_args['worker'] = $worker_id;
//		$app_id = $this->factory->appointment->create_object( $app_args );
//
//		$appointments = appointments();
//		$appointments->worker = $worker_id;
//		$appointments->service = $service_id;
//
//		$from = strtotime( date( 'Y-m-d', $next_monday ) . ' 12:00:00' );
//		$to = strtotime( date( 'Y-m-d', $next_monday ) . ' 12:15:00' );
//
//		$busy = $appointments->is_busy( $from, $to, 1 );
//		$this->assertTrue( $busy );
//
//		$from = strtotime( date( 'Y-m-d', $next_monday ) . ' 10:00:00' );
//		$to = strtotime( date( 'Y-m-d', $next_monday ) . ' 10:15:00' );
//
//		$busy = $appointments->is_busy( $from, $to, 1 );
//		$this->assertFalse( $busy );
	}

}
