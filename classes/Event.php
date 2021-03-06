<?php
class Event {
	public $db_event;
	public $app;
	public $game;
	
	public function __construct(&$game, $db_event, $event_id) {
		$this->game = $game;
		
		if ($db_event) {
			$this->db_event = $db_event;
		}
		else {
			$q = "SELECT * FROM events ev JOIN event_types et ON ev.event_type_id=et.event_type_id LEFT JOIN entities en ON et.entity_id=en.entity_id WHERE ev.event_id='".$event_id."';";
			$r = $this->game->blockchain->app->run_query($q);
			$this->db_event = $r->fetch() or die("Error, could not load event #".$event_id);
		}
		
		$q = "SELECT * FROM event_outcomes WHERE event_id='".$this->db_event['event_id']."';";
		$r = $this->game->blockchain->app->run_query($q);
		if ($r->rowCount() > 0) {
			$this->event_outcome = $r->fetch();
		}
		else $this->event_outcome = false;
	}
	
	public function round_voting_stats($round_id) {
		$last_block_id = $this->game->blockchain->last_block_id();
		$current_round = $this->game->block_to_round($last_block_id+1);
		
		if ($round_id == $current_round) {
			$q = "SELECT * FROM options op LEFT JOIN images i ON op.image_id=i.image_id WHERE op.event_id='".$this->db_event['event_id']."' ORDER BY (op.votes+op.unconfirmed_votes) DESC, op.option_id ASC;";
			return $this->game->blockchain->app->run_query($q);
		}
		else {
			$q = "SELECT op.*, gio.*, SUM(gio.votes) AS votes, SUM(gio.colored_amount) AS coin_score, SUM(gio.coin_blocks_destroyed) AS coin_block_score, SUM(gio.coin_rounds_destroyed) AS coin_round_score FROM transaction_game_ios gio JOIN options op ON gio.option_id=op.option_id LEFT JOIN images im ON op.image_id=im.image_id WHERE op.event_id='".$this->db_event['event_id']."' AND gio.create_round_id=".$round_id." GROUP BY gio.option_id ORDER BY SUM(gio.votes) DESC, gio.option_id ASC;";
			return $this->game->blockchain->app->run_query($q);
		}
	}

	public function total_votes_in_round($round_id, $include_unconfirmed) {
		$sum = 0;
		
		$base_q = "SELECT SUM(votes) FROM transaction_game_ios WHERE event_id='".$this->db_event['event_id']."' AND option_id > 0";
		$confirmed_q = $base_q." AND create_round_id=".$round_id.";";
		$confirmed_r = $this->game->blockchain->app->run_query($confirmed_q);
		$confirmed_votes = $confirmed_r->fetch(PDO::FETCH_NUM);
		$confirmed_votes = $confirmed_votes[0];
		if ($confirmed_votes > 0) {} else $confirmed_votes = 0;
		
		$sum += $confirmed_votes;
		$returnvals['confirmed'] = $confirmed_votes;
		
		if ($include_unconfirmed) {
			$q = "SELECT SUM(unconfirmed_votes) FROM options WHERE event_id='".$this->db_event['event_id']."';";
			$r = $this->game->blockchain->app->run_query($q);
			$sums = $r->fetch(PDO::FETCH_NUM);
			
			$unconfirmed_votes = $sums[0];
			$sum += $unconfirmed_votes;
			$returnvals['unconfirmed'] = $unconfirmed_votes;
		}
		else $returnvals['unconfirmed'] = 0;
		
		$returnvals['sum'] = $sum;
		
		return $returnvals;
	}

	public function round_voting_stats_all($voting_round) {
		$round_voting_stats = $this->round_voting_stats($voting_round);
		$stats_all = false;
		$counter = 0;
		$option_id_csv = "";
		$option_id_to_rank = "";
		
		while ($stat = $round_voting_stats->fetch()) {
			$stats_all[$counter] = $stat;
			$option_id_csv .= $stat['option_id'].",";
			$option_id_to_rank[$stat['option_id']] = $counter;
			$counter++;
		}
		if ($option_id_csv != "") $option_id_csv = substr($option_id_csv, 0, strlen($option_id_csv)-1);
		
		$q = "SELECT * FROM options gvo LEFT JOIN images i ON gvo.image_id=i.image_id WHERE gvo.event_id='".$this->db_event['event_id']."'";
		if ($option_id_csv != "") $q .= " AND gvo.option_id NOT IN (".$option_id_csv.")";
		$q .= " ORDER BY gvo.option_id ASC;";
		$r = $this->game->blockchain->app->run_query($q);
		
		while ($stat = $r->fetch()) {
			$stat['votes'] = 0;
			$stat['unconfirmed_votes'] = 0;
			
			$stats_all[$counter] = $stat;
			$option_id_to_rank[$stat['option_id']] = $counter;
			$counter++;
		}
		
		$current_round = $this->game->block_to_round($this->game->blockchain->last_block_id()+1);
		if ($voting_round == $current_round) $include_unconfirmed = true;
		else $include_unconfirmed = false;
		
		$sum_votes = $this->total_votes_in_round($voting_round, $include_unconfirmed);
		$output_arr[0] = $sum_votes['sum'];
		$output_arr[1] = floor($sum_votes['sum']*$this->db_event['max_voting_fraction']);
		$output_arr[2] = $stats_all;
		$output_arr[3] = $option_id_to_rank;
		$output_arr[4] = $sum_votes['confirmed'];
		$output_arr[5] = $sum_votes['unconfirmed'];
		
		return $output_arr;
	}

	public function get_round_winner($round_stats_all) {
		$winner_option_id = false;
		$winner_index = false;
		$max_sum_votes = $round_stats_all[1];
		$round_stats = $round_stats_all[2];
		
		for ($i=0; $i<count($round_stats); $i++) {
			if (!$winner_option_id && $round_stats[$i]['votes'] <= $max_sum_votes && $round_stats[$i]['votes'] > 0) {
				$winner_option_id = $round_stats[$i]['option_id'];
				$winner_index = $i;
			}
		}
		if ($winner_option_id) {
			$q = "SELECT * FROM options WHERE option_id='".$winner_option_id."';";
			$r = $this->game->blockchain->app->run_query($q);
			$option = $r->fetch();
			
			$option['winning_votes'] = $round_stats[$winner_index]['votes'];
			
			return $option;
		}
		else return false;
	}
	
	public function current_round_table($current_round, $user, $show_intro_text, $clickable, $game_instance_id, $game_event_index) {
		$score_field = $this->game->db_game['payout_weight']."_score";
		
		$last_block_id = $this->game->blockchain->last_block_id();
		$current_round = $this->game->block_to_round($last_block_id+1);
		$block_within_round = $this->game->block_id_to_round_index($last_block_id+1);
		
		$round_stats_all = $this->round_voting_stats_all($current_round);
		$sum_votes = $round_stats_all[0];
		$max_sum_votes = $round_stats_all[1];
		$round_stats = $round_stats_all[2];
		$confirmed_sum_votes = $round_stats_all[4];
		$unconfirmed_sum_votes = $round_stats_all[5];
		
		$winner_option_id = FALSE;
		
		$html = '<div id="game'.$game_instance_id.'_event'.$game_event_index.'_round_table" class="round_table">';
		
		$option_max_width = $this->db_event['option_max_width'];
		$sq_px_per_pct_point = pow($option_max_width, 2)/100;
		$min_px_diam = 30;
		
		if ($block_within_round != $this->game->db_game['round_length']) $html .= "<h2>".$this->db_event['event_name']."</h2>\n";
		else {
			$winner = $this->get_round_winner($round_stats_all);
			if ($winner) $html .= "<h1>".$winner['name']."</h1>";
			else $html .= "<h1>No winner in ".$this->db_event['event_name']."</h1>";
		}
		
		if (TRUE || $show_intro_text) {
			$detail_html = "";
			if ($block_within_round == $this->game->db_game['round_length']) {
				$html .= $this->game->blockchain->app->format_bignum($sum_votes/pow(10,8)).' votes were cast in this round.<br/>';
				$my_votes = $this->my_votes_in_round($current_round, $user->db_user['user_id'], false);
				$fees_paid = $my_votes['fee_amount'];

				if (!empty($winner) && $this->game->db_game['game_winning_rule'] == "event_points") {
					$q = "SELECT * FROM entities WHERE entity_id='".$winner['entity_id']."';";
					$r = $this->game->blockchain->app->run_query($q);
					$entity = $r->fetch();
					$html .= $entity['entity_name']." won ".$this->db_event[$this->game->db_game['game_winning_field']]." electoral votes<br/>\n";
				}
				if (empty($my_votes[0])) {
					if (!empty($winner['name'])) {
						$my_winning_votes = 0;
						$html .= "You didn't cast any votes for ".$winner['name'].".<br/>\n";
					}
				}
				else {
					$my_winning_votes = $my_votes[0][$winner['option_id']]["votes"];
					$win_amount = floor($this->event_pos_reward_in_round($current_round)*$my_winning_votes/$winner['winning_votes'] - $fees_paid)/pow(10,8);
					$html .= "You correctly cast ".$this->game->blockchain->app->format_bignum($my_winning_votes/pow(10,8))." votes";
					$html .= ' and won <font class="greentext">+'.$this->game->blockchain->app->format_bignum($win_amount)."</font> coins.<br/>\n";
				}
			}
			else {
				$html .= 'Mining block '.$block_within_round.'/'.$this->game->db_game['round_length'].'. ';
				
				$detail_html .= '<div class="row"><div class="col-sm-6 boldtext">Cap:</div><div class="col-sm-6">';
				$detail_html .= ($this->db_event['max_voting_fraction']*100).'%';
				$detail_html .= '</div></div>';
				
				if ($this->game->db_game['game_winning_rule'] == "event_points") {
					$field_disp = ucwords(str_replace("_", " ", $this->game->db_game['game_winning_field']));
					$detail_html .= '<div class="row"><div class="col-sm-6 boldtext">'.$field_disp.'</div><div class="col-sm-6">'.$this->db_event[$this->game->db_game['game_winning_field']].'</div></div>';
				}
				
				$detail_html .= '<div class="row"><div class="col-sm-6 boldtext">Confirmed Votes:</div><div class="col-sm-6">'.$this->game->blockchain->app->format_bignum($confirmed_sum_votes/pow(10,8)).' votes</div></div>';
				
				$detail_html .= '<div class="row"><div class="col-sm-6 boldtext">Unconfirmed Votes:</div><div class="col-sm-6">'.$this->game->blockchain->app->format_bignum($unconfirmed_sum_votes/pow(10,8)).' votes</div></div>';
				
				$detail_html .= '<div class="row"><div class="col-sm-6 boldtext">Total Payout:</div><div class="col-sm-6">';
				$payout_disp = $this->game->blockchain->app->format_bignum($this->event_pos_reward_in_round($current_round)/pow(10,8));
				$detail_html .= $payout_disp.' ';
				if ($payout_disp == '1') $detail_html .= $this->game->db_game['coin_name'];
				else $detail_html .= $this->game->db_game['coin_name_plural'];
				$detail_html .= '</div></div>';
				
				$seconds_left = round(($this->game->db_game['round_length'] - $last_block_id%$this->game->db_game['round_length'] - 1)*$this->game->db_game['seconds_per_block']);
				$detail_html .= '<div class="row"><div class="col-sm-6 boldtext">Time Left:</div><div class="col-sm-6">';
				$detail_html .= $this->game->blockchain->app->format_seconds($seconds_left);
				$detail_html .= '</div></div>';
			}
			
			if ($this->db_event['vote_effectiveness_function'] != "constant") {
				$q = "SELECT SUM(".$this->game->db_game['payout_weight']."_score), SUM(unconfirmed_".$this->game->db_game['payout_weight']."_score), SUM(votes), SUM(unconfirmed_votes) FROM options WHERE event_id='".$this->db_event['event_id']."';";
				$r = $this->game->blockchain->app->run_query($q);
				$score_votes = $r->fetch();
				$score = ($score_votes['SUM('.$this->game->db_game['payout_weight'].'_score)']+$score_votes['SUM(unconfirmed_'.$this->game->db_game['payout_weight'].'_score)']);
				$votes = $score_votes['SUM(votes)']+$score_votes['SUM(unconfirmed_votes)'];
				if ($score > 0) $average_effectiveness = $votes/$score;
				else $average_effectiveness = 1;
				
				if ($this->block_id_to_effectiveness_factor($last_block_id+1) > 0) $html .= "Votes are ".round(100*$this->block_id_to_effectiveness_factor($last_block_id+1))."% effective right now. \n";
				$detail_html .= '<div class="row"><div class="col-sm-6 boldtext">Average Effectiveness:</div><div class="col-sm-6">'.round(100*$average_effectiveness, 2)."%";
				if ($this->game->db_game['inflation'] == "exponential") {
					$detail_html .= " (".$this->game->blockchain->app->format_bignum($this->game->blockchain->app->votes_per_coin($this->game->db_game)*$average_effectiveness)." votes per coin)";
				}
				$detail_html .= "</div></div>\n";
			}
			if ($detail_html != "") {
				$html .= " <a href=\"\" onclick=\"games[".$game_instance_id."].events[".$game_event_index."].toggle_details(); return false;\">Details</a><br/>";
				$html .= "<div id=\"game".$game_instance_id."_event".$game_event_index."_details\">".$detail_html."</div>";
			}
		}
		
		for ($i=0; $i<count($round_stats); $i++) {
			$option_votes = $round_stats[$i]['votes'] + $round_stats[$i]['unconfirmed_votes'];
			
			if (!$winner_option_id && $option_votes <= $max_sum_votes && $option_votes > 0) $winner_option_id = $round_stats[$i]['option_id'];
			
			if ($sum_votes > 0) {
				$pct_votes = 100*(floor(1000*$option_votes/$sum_votes)/1000);
			}
			else $pct_votes = 0;
			
			$sq_px = $pct_votes*$sq_px_per_pct_point;
			$box_diam = round(sqrt($sq_px));
			if ($box_diam < $min_px_diam) $box_diam = $min_px_diam;
			
			$holder_width = $box_diam;
			
			$show_boundbox = false;
			if ($i == 0 || $option_votes > $max_sum_votes) {
				$show_boundbox = true;
				$boundbox_sq_px = $this->db_event['max_voting_fraction']*100*$sq_px_per_pct_point;
				$boundbox_diam = round(sqrt($boundbox_sq_px));
				if ($boundbox_diam > $holder_width) $holder_width = $boundbox_diam;
			}
			
			$html .= '
			<div class="vote_option_box_container">
				<div class="vote_option_label';
				if ($option_votes > $max_sum_votes) $html .=  " redtext";
				else if ($winner_option_id == $round_stats[$i]['option_id']) $html .=  " greentext";
				$html .= '"';
				if ($clickable) $html .= ' style="cursor: pointer;" onclick="games['.$game_instance_id.'].events['.$game_event_index.'].option_selected('.$i.'); games['.$game_instance_id.'].events['.$game_event_index.'].start_vote('.$round_stats[$i]['option_id'].');"';
				$html .= '>'.$round_stats[$i]['name'].' ('.$pct_votes.'%)</div>
				<div class="stage vote_option_box_holder" style="height: '.$holder_width.'px; width: '.$holder_width.'px;">';
				if ($show_boundbox) {
					$html .= '<div onclick="games['.$game_instance_id.'].events['.$game_event_index.'].option_selected('.$i.'); games['.$game_instance_id.'].events['.$game_event_index.'].start_vote('.$round_stats[$i]['option_id'].');" class="vote_option_boundbox" style="cursor: pointer; height: '.$boundbox_diam.'px; width: '.$boundbox_diam.'px;';
					if ($holder_width != $boundbox_diam) $html .= 'left: '.(($holder_width-$boundbox_diam)/2).'px; top: '.(($holder_width-$boundbox_diam)/2).'px;';
					$html .= '"></div>';
				}
				$html .= '
					<div class="ball vote_option_box" style="width: '.$box_diam.'px; height: '.$box_diam.'px;';
					if ($holder_width != $box_diam) $html .= 'left: '.(($holder_width-$box_diam)/2).'px; top: '.(($holder_width-$box_diam)/2).'px;';
					if ($round_stats[$i]['image_id'] > 0) $html .= 'background-image: url(\''.$this->game->blockchain->app->image_url($round_stats[$i]).'\');';
					if ($clickable) $html .= 'cursor: pointer;';
					if ($option_votes > $max_sum_votes) $html .= 'opacity: 0.5; z-index: 1;';
					else $html .= 'z-index: 3;';
					$html .= '" id="game'.$game_instance_id.'_event'.$game_event_index.'_vote_option_'.$i.'"';
					if ($clickable) $html .= ' onmouseover="games['.$game_instance_id.'].events['.$game_event_index.'].option_selected('.$i.');" onclick="games['.$game_instance_id.'].events['.$game_event_index.'].option_selected('.$i.'); games['.$game_instance_id.'].events['.$game_event_index.'].start_vote('.$round_stats[$i]['option_id'].');"';
					$html .= '>
						<input type="hidden" id="game'.$game_instance_id.'_event'.$game_event_index.'_option_id2rank_'.$round_stats[$i]['option_id'].'" value="'.$i.'" />
						<input type="hidden" id="game'.$game_instance_id.'_event'.$game_event_index.'_rank2option_id_'.$i.'" value="'.$round_stats[$i]['option_id'].'" />
					</div>
				</div>
			</div>';
		}
		$html .= "</div>";
		
		return $html;
	}
	
	public function new_payout_transaction($round_id, $block_id, $winning_option, $winning_votes) {
		$log_text = "";
		
		if ($this->game->db_game['payout_weight'] == "coin") $score_field = "colored_amount";
		else $score_field = $this->game->db_game['payout_weight']."s_destroyed";
		
		// Loop through the correctly voted UTXOs
		$q = "SELECT * FROM transaction_game_ios gio JOIN transaction_ios io ON gio.io_id=io.io_id JOIN addresses a ON io.address_id=a.address_id WHERE gio.event_id='".$this->db_event['event_id']."' AND gio.create_round_id=".$round_id." AND io.create_block_id != ".$block_id." AND gio.option_id=".$winning_option.";";
		$r = $this->game->blockchain->app->run_query($q);
		$total_paid = 0;
		$out_index = 0;
		
		$event_pos_reward = $this->event_pos_reward_in_round($round_id);
		
		$this->game->blockchain->app->log($winning_option." wins, ".$r->rowCount()." correct votes, payout: ".$event_pos_reward/pow(10,8));
		while ($input = $r->fetch()) {
			$payout_amount = floor($event_pos_reward*$input['votes']/$winning_votes);
			$total_paid += $payout_amount;
			
			$qq = "INSERT INTO transaction_game_ios SET io_id='".$input['io_id']."', is_coinbase=1, instantly_mature=0, game_id='".$this->game->db_game['game_id']."', event_id='".$this->db_event['event_id']."'";
			if ($winning_option > 0) $qq .= ", option_id='".$winning_option."'";
			$qq .= ", colored_amount='".$payout_amount."', create_round_id='".$round_id."';";
			$rr = $this->game->blockchain->app->run_query($qq);
			$output_id = $this->game->blockchain->app->last_insert_id();
			
			$this->game->blockchain->app->log($output_id." ".$qq);
			
			$qq = "UPDATE transaction_game_ios SET payout_game_io_id='".$output_id."' WHERE game_io_id='".$input['game_io_id']."';";
			$rr = $this->game->blockchain->app->run_query($qq);
			
			$payout_disp = $payout_amount/(pow(10,8));
			$log_text .= "Pay ".$payout_disp." ";
			if ($payout_disp == '1') $log_text .= $this->game->db_game['coin_name'];
			else $log_text .= $this->game->db_game['coin_name_plural'];
			$log_text .= " to ".$input['address']."<br/>\n";
			$out_index++;
		}
		
		return $log_text;
	}
	
	/*public function new_betbase_transaction($round_id, $mining_block_id, $winning_option) {
		$log_text = "";
		
		$q = "INSERT INTO transactions SET event_id='".$this->db_event['event_id']."'";
		if ($this->db_event['event_type'] == "simulation") $q .= ", tx_hash='".$this->game->blockchain->app->random_string(64)."'";
		$q .= ", transaction_desc='betbase', block_id='".($mining_block_id-1)."', time_created='".time()."', has_all_inputs=1, has_all_outputs=1;";
		$r = $this->game->blockchain->app->run_query($q);
		$transaction_id = $this->game->blockchain->app->last_insert_id();
		
		$bet_mid_q = "transaction_ios i, addresses a WHERE i.event_id='".$this->db_event['event_id']."' AND i.address_id=a.address_id AND a.bet_round_id = ".$round_id." AND i.create_block_id <= ".$this->round_to_last_betting_block($round_id);
		
		$total_burned_q = "SELECT SUM(i.amount) FROM ".$bet_mid_q.";";
		$total_burned_r = $this->game->blockchain->app->run_query($total_burned_q);
		$total_burned = $total_burned_r->fetch(PDO::FETCH_NUM);
		$total_burned = $total_burned[0];
		
		if ($total_burned > 0) {
			$winners_burned_q = "SELECT SUM(i.amount) FROM ".$bet_mid_q;
			if ($winning_option) $winners_burned_q .= " AND bet_option_id=".$winning_option.";";
			else $winners_burned_q .= " AND bet_option_id IS NULL;";
			$winners_burned_r = $this->game->blockchain->app->run_query($winners_burned_q);
			$winners_burned = $winners_burned_r->fetch(PDO::FETCH_NUM);
			$winners_burned = $winners_burned[0];
			
			$win_multiplier = 0;
			if ($winners_burned > 0) $win_multiplier = floor(pow(10,8)*$total_burned/$winners_burned)/pow(10,8);
			
			$log_text .= $total_burned/pow(10,8)." coins should be paid to the winning bettors (x".$win_multiplier.").<br/>\n";
			
			if ($winners_burned > 0) {
				$bet_winners_q = "SELECT * FROM ".$bet_mid_q." AND bet_option_id=".$winning_option.";";
				$bet_winners_r = $this->game->blockchain->app->run_query($bet_winners_q);
				
				$betbase_sum = 0;
				
				while ($bet_winner = $bet_winners_r->fetch()) {
					$win_amount = floor($bet_winner['amount']*$win_multiplier);
					$payback_address = bet_transaction_payback_address($bet_winner['create_transaction_id']);
					
					if ($payback_address) {
						$qq = "INSERT INTO transaction_ios SET spend_status='unspent', instantly_mature=0, event_id='".$this->db_event['event_id']."', user_id='".$payback_address['user_id']."', address_id='".$payback_address['address_id']."'";
						if ($payback_address['option_id'] > 0) $qq .= ", option_id=".$payback_address['option_id'];
						$qq .= ", create_transaction_id='".$transaction_id."', colored_amount='".$colored_amount."', amount='".$win_amount."', create_block_id='".($mining_block_id-1)."', create_round_id='".$this->block_to_round($mining_block_id-1)."';";
						$rr = $this->game->blockchain->app->run_query($qq);
						$output_id = $this->game->blockchain->app->last_insert_id();
						
						$qq = "UPDATE transaction_ios SET payout_io_id='".$output_id."' WHERE io_id='".$bet_winner['io_id']."';";
						$rr = $this->game->blockchain->app->run_query($qq);
						
						$log_text .= "Pay ".$win_amount/(pow(10,8))." coins to ".$payback_address['address']." for winning the bet.<br/>\n";
						
						$betbase_sum += $win_amount;
					}
					else $log_text .= "No payback address was found for transaction #".$bet_winner['create_transaction_id']."<br/>\n";
				}
				
				$q = "UPDATE transactions SET amount='".$betbase_sum."' WHERE transaction_id='".$transaction_id."';";
				$r = $this->game->blockchain->app->run_query($q);
			}
			else $log_text .= "None of the bettors predicted this outcome!<br/>\n";
		}
		else {
			$log_text .= "No one placed losable bets on this round.<br/>\n";
			$q = "DELETE FROM transactions WHERE transaction_id='".$transaction_id."';";
			$r = $this->game->blockchain->app->run_query($q);
			$transaction_id = false;
		}
		
		$returnvals[0] = $transaction_id;
		$returnvals[1] = $log_text;
		
		return $returnvals;
	}*/
	
	public function my_votes_in_round($round_id, $user_id, $include_unconfirmed) {
		$q = "SELECT SUM(t_fees.fee_amount) FROM (SELECT t.fee_amount FROM transaction_game_ios gio JOIN transaction_ios io ON io.io_id=gio.io_id JOIN options op ON gio.option_id=op.option_id JOIN transactions t ON io.create_transaction_id=t.transaction_id WHERE gio.game_id='".$this->game->db_game['game_id']."' AND gio.create_round_id = ".$round_id." AND io.user_id='".$user_id."' GROUP BY t.transaction_id) t_fees;";
		$r = $this->game->blockchain->app->run_query($q);
		$fee_amount = $r->fetch(PDO::FETCH_NUM);
		$fee_amount = $fee_amount[0];
		
		$q = "SELECT op.*, SUM(gio.colored_amount), SUM(gio.coin_blocks_destroyed), SUM(gio.coin_rounds_destroyed), SUM(gio.votes) FROM transaction_game_ios gio JOIN transaction_ios io ON gio.io_id=io.io_id JOIN options op ON gio.option_id=op.option_id JOIN transactions t ON io.create_transaction_id=t.transaction_id WHERE gio.event_id='".$this->db_event['event_id']."' AND gio.create_round_id=".$round_id." AND io.user_id='".$user_id."' GROUP BY gio.option_id ORDER BY op.option_id ASC;";
		$r = $this->game->blockchain->app->run_query($q);
		$coins_voted = 0;
		$coin_blocks_voted = 0;
		$coin_rounds_voted = 0;
		$votes = 0;
		$my_votes = array();
		while ($votesum = $r->fetch()) {
			$my_votes[$votesum['option_id']]['coins'] = $votesum['SUM(gio.colored_amount)'];
			$my_votes[$votesum['option_id']]['coin_blocks'] = $votesum['SUM(gio.coin_blocks_destroyed)'];
			$my_votes[$votesum['option_id']]['coin_rounds'] = $votesum['SUM(gio.coin_rounds_destroyed)'];
			$my_votes[$votesum['option_id']]['votes'] = $votesum['SUM(gio.votes)'];
			$coins_voted += $votesum['SUM(gio.colored_amount)'];
			$coin_blocks_voted += $votesum['SUM(gio.coin_blocks_destroyed)'];
			$coin_rounds_voted += $votesum['SUM(gio.coin_rounds_destroyed)'];
			$votes += $votesum['SUM(gio.votes)'];
		}
		$returnvals[0] = $my_votes;
		$returnvals[1] = $coins_voted;
		$returnvals[2] = $coin_blocks_voted;
		$returnvals[3] = $coin_rounds_voted;
		$returnvals[4] = $votes;
		$returnvals['fee_amount'] = $fee_amount;
		return $returnvals;
	}

	public function my_votes_table($round_id, $user) {
		$last_block_id = $this->game->blockchain->last_block_id();
		$current_round = $this->game->block_to_round($last_block_id+1);
		
		$html = "";
		
		$confirmed_html = "";
		$num_confirmed = 0;
		
		$unconfirmed_html = "";
		$num_unconfirmed = 0;
		
		if ($this->game->db_game['payout_weight'] == "coin") $score_field = "gio.colored_amount";
		else {
			if ($this->game->db_game['payout_weight'] == "coin_block") $score_field = "gio.coin_blocks_destroyed";
			else $score_field = "gio.coin_rounds_destroyed";
		}
		
		$q = "SELECT op.*, t.transaction_id, t.fee_amount, io.spend_status, SUM(gio.colored_amount*gio.effectiveness_factor), SUM(gio.coin_blocks_destroyed*gio.effectiveness_factor), SUM(gio.coin_rounds_destroyed*gio.effectiveness_factor) FROM transaction_game_ios gio JOIN transaction_ios io ON gio.io_id=io.io_id JOIN transactions t ON io.create_transaction_id=t.transaction_id JOIN options op ON gio.option_id=op.option_id WHERE gio.event_id='".$this->db_event['event_id']."' AND gio.create_round_id=".$round_id." AND io.user_id='".$user->db_user['user_id']."' GROUP BY gio.option_id ORDER BY SUM(".$score_field.") DESC;";
		$r = $this->game->blockchain->app->run_query($q);
		
		while ($my_vote = $r->fetch()) {
			$color = "green";
			$num_votes = $my_vote['SUM('.$score_field.'*gio.effectiveness_factor)'];
			$option_votes = $this->option_votes_in_round($my_vote['option_id'], $round_id);
			if ($option_votes['sum'] > 0) $expected_payout = floor($this->event_pos_reward_in_round($round_id)*($num_votes/$option_votes['sum'])-$my_vote['fee_amount'])/pow(10,8);
			else $expected_payout = 0;
			if ($expected_payout < 0) $expected_payout = 0;
			
			$confirmed_html .= '<div class="row">';
			$confirmed_html .= '<div class="col-sm-4 '.$color.'text">'.$my_vote['name'].'</div>';
			$confirmed_html .= '<div class="col-sm-3 '.$color.'text"><a target="_blank" href="/explorer/games/'.$this->game->db_game['url_identifier'].'/transactions/'.$my_vote['transaction_id'].'">'.$this->game->blockchain->app->format_bignum($num_votes/pow(10,8), 2).' votes</a></div>';
			
			$payout_disp = $this->game->blockchain->app->format_bignum($expected_payout);
			$confirmed_html .= '<div class="col-sm-5 '.$color.'text">+'.$payout_disp.' ';
			if ($payout_disp == '1') $confirmed_html .= $this->game->db_game['coin_name'];
			else $confirmed_html .= $this->game->db_game['coin_name_plural'];
			$confirmed_html .= '</div>';
			
			$confirmed_html .= "</div>\n";
			
			$num_confirmed++;
		}
		
		$q = "SELECT gvo.*, gio.*, t.transaction_id, t.fee_amount, t.amount AS transaction_amount FROM transaction_game_ios gio JOIN transaction_ios io ON gio.io_id=io.io_id JOIN transactions t ON io.create_transaction_id=t.transaction_id JOIN options gvo ON gio.option_id=gvo.option_id WHERE gio.event_id='".$this->db_event['event_id']."' AND io.create_block_id IS NULL AND t.block_id IS NULL AND io.user_id='".$user->db_user['user_id']."' ORDER BY gio.colored_amount DESC;";
		$r = $this->game->blockchain->app->run_query($q);
		
		while ($my_vote = $r->fetch()) {
			$color = "yellow";
			$option_votes = $this->option_votes_in_round($my_vote['option_id'], $round_id);
			
			if ($this->game->db_game['payout_weight'] == "coin_block") {
				$num_votes = $my_vote['ref_coin_blocks'] + ((1+$last_block_id)-$my_vote['ref_block_id'])*$my_vote['colored_amount'];
			}
			else if ($this->game->db_game['payout_weight'] == "coin_round") {
				$num_votes = $my_vote['ref_coin_rounds'] + ($this->game->block_to_round(1+$last_block_id)-$my_vote['ref_round_id'])*$my_vote['colored_amount'];
			}
			else {
				$num_votes = $my_vote['colored_amount'];
			}
			
			$num_votes = floor($num_votes*$this->block_id_to_effectiveness_factor($last_block_id+1));
			if ($option_votes['sum'] > 0) $expected_payout = floor($this->event_pos_reward_in_round($round_id)*($num_votes/$option_votes['sum'])-$my_vote['fee_amount'])/pow(10,8);
			else $expected_payout = 0;
			if ($expected_payout < 0) $expected_payout = 0;
			
			$unconfirmed_html .= '<div class="row">';
			$unconfirmed_html .= '<div class="col-sm-4 '.$color.'text">'.$my_vote['name'].'</div>';
			$unconfirmed_html .= '<div class="col-sm-3 '.$color.'text"><a target="_blank" href="/explorer/games/'.$this->game->db_game['url_identifier'].'/transactions/'.$my_vote['transaction_id'].'">'.$this->game->blockchain->app->format_bignum($num_votes/pow(10,8), 2).' votes</a></div>';
			
			$payout_disp = $this->game->blockchain->app->format_bignum($expected_payout);
			$unconfirmed_html .= '<div class="col-sm-5 '.$color.'text">+'.$payout_disp.' ';
			if ($payout_disp == '1') $unconfirmed_html .= $this->game->db_game['coin_name'];
			else $unconfirmed_html .= $this->game->db_game['coin_name_plural'];
			$unconfirmed_html .= '</div>';
			
			$unconfirmed_html .= "</div>\n";
			
			$num_unconfirmed++;
		}
		
		if ($num_unconfirmed + $num_confirmed > 0) {
			$html .= '
			<div class="my_votes_table">
				<div class="row my_votes_header">
					<div class="col-sm-4">'.ucwords($this->db_event['option_name']).'</div>
					<div class="col-sm-3">Amount</div>
					<div class="col-sm-5">Payout</div>
				</div>
				'.$unconfirmed_html.$confirmed_html.'
			</div>';
		}
		
		return $html;
	}
	
	public function initialize_vote_option_details($option_id2rank, $sum_votes, $user_id, $game_instance_id, $game_event_index) {
		$html = "";
		$option_q = "SELECT * FROM options WHERE event_id='".$this->db_event['event_id']."' ORDER BY option_id ASC;";
		$option_r = $this->game->blockchain->app->run_query($option_q);
		
		$last_block_id = $this->game->last_block_id();
		$current_round = $this->game->block_to_round($last_block_id+1);
		
		while ($option = $option_r->fetch()) {
			if (!$option['last_win_round']) $losing_streak = false;
			else $losing_streak = $current_round - $option['last_win_round'] - 1;
			
			$rank = $option_id2rank[$option['option_id']]+1;
			$confirmed_votes = $option['votes'];
			$unconfirmed_votes = $option['unconfirmed_votes'];
			
			$html .= '
			<div style="display: none;" class="modal fade" id="game'.$game_instance_id.'_event'.$game_event_index.'_vote_confirm_'.$option['option_id'].'">
				<div class="modal-dialog">
					<div class="modal-content">
						<div class="modal-body">
							<h2>Vote for '.$option['name'].'</h2>
							<div id="game'.$game_instance_id.'_event'.$game_event_index.'_vote_option_details_'.$option['option_id'].'">
								'.$this->game->blockchain->app->vote_option_details($option, $rank, $confirmed_votes, $unconfirmed_votes, $sum_votes, $losing_streak).'
							</div>
							<div id="game'.$game_instance_id.'_event'.$game_event_index.'_vote_details_'.$option['option_id'].'"></div>
							<div class="redtext" id="game'.$game_instance_id.'_event'.$game_event_index.'_vote_error_'.$option['option_id'].'"></div>
						</div>
						<div class="modal-footer">
							<button class="btn btn-primary" id="game'.$game_instance_id.'_event'.$game_event_index.'_vote_confirm_btn_'.$option['option_id'].'" onclick="games['.$game_instance_id.'].add_option_to_vote('.$option['option_id'].', \''.$option['name'].'\');">Add '.$option['name'].' to my vote</button>
							<button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
						</div>
					</div>
				</div>
			</div>';
		}
		return $html;
	}
	
	public function set_event_completed() {
		$q = "UPDATE events SET completion_datetime=NOW() WHERE event_id='".$this->db_event['event_id']."';";
		$r = $this->game->blockchain->app->run_query($q);
	}
	
	public function ensure_options() {
		/*$qq = "SELECT * FROM option_group_memberships mem JOIN entities ent ON mem.entity_id=ent.entity_id WHERE mem.option_group_id='".$this->db_event['option_group_id']."' AND NOT EXISTS (SELECT * FROM options op WHERE op.event_id='".$this->db_event['event_id']."' AND op.entity_id=mem.entity_id);";
		$rr = $this->game->blockchain->app->run_query($qq);
		while ($required_membership = $rr->fetch()) {
			$qqq = "INSERT INTO options SET event_id='".$this->db_event['event_id']."', entity_id='".$required_membership['entity_id']."', membership_id='".$required_membership['membership_id']."'";
			if ($required_membership['default_image_id'] > 0) $qqq .= ", image_id='".$required_membership['default_image_id']."'";
			$qqq .= ", name='".$required_membership['name']."', voting_character='".$required_membership['voting_character']."';";
			$rrr = $this->game->blockchain->app->run_query($qqq);
		}*/
	}
	
	public function delete_options() {
		$qq = "DELETE FROM options WHERE event_id='".$this->db_event['event_id']."';";
		$rr = $this->game->blockchain->app->run_query($qq);
	}
	
	public function round_to_last_betting_block($round_id) {
		return ($round_id-1)*$this->db_event['round_length']+5;
	}
	
	public function round_index_to_effectiveness_factor($round_index) {
		if ($this->db_event['vote_effectiveness_function'] == "linear_decrease") {
			return floor(pow(10,8)*($this->game->db_game['round_length']-$round_index)/($this->game->db_game['round_length']-1))/pow(10,8);
		}
		else return 1;
	}
	
	public function block_id_to_effectiveness_factor($block_id) {
		return $this->round_index_to_effectiveness_factor($this->game->block_id_to_round_index($block_id));
	}
	
	public function option_votes_in_round($option_id, $round_id) {
		if ($this->game->db_game['payout_weight'] == "coin") $score_field = "colored_amount";
		else $score_field = $this->game->db_game['payout_weight']."s_destroyed";
		
		$mining_block_id = $this->game->blockchain->last_block_id()+1;
		$current_round_id = $this->game->block_to_round($mining_block_id);
		
		if ($current_round_id == $round_id) {
			$q = "SELECT coin_score, unconfirmed_coin_score, coin_block_score, unconfirmed_coin_block_score, coin_round_score, unconfirmed_coin_round_score, votes, unconfirmed_votes FROM options WHERE option_id='".$option_id."' AND event_id='".$this->db_event['event_id']."';";
			$r = $this->game->blockchain->app->run_query($q);
			$sums = $r->fetch();
			$confirmed_score = $sums['votes'];
			$unconfirmed_score = $sums['unconfirmed_votes'];
		}
		else {
			$q = "SELECT SUM(".$score_field."), SUM(votes) FROM transaction_game_ios WHERE event_id='".$this->db_event['event_id']."' AND ";
			$q .= "create_round_id=".$round_id." AND option_id='".$option_id."';";
			$r = $this->game->blockchain->app->run_query($q);
			$confirmed_score = $r->fetch(PDO::FETCH_NUM);
			$confirmed_score = $confirmed_score[1];
			$unconfirmed_score = 0;
		}
		
		if (!$confirmed_score) $confirmed_score = 0;
		if (!$unconfirmed_score) $unconfirmed_score = 0;
		
		return array('confirmed'=>$confirmed_score, 'unconfirmed'=>$unconfirmed_score, 'sum'=>$confirmed_score+$unconfirmed_score);
	}
	
	public function event_pos_reward_in_round($round_id) {
		if ($this->game->db_game['inflation'] == "linear") return $this->game->db_game['pos_reward'];
		else {
			$mining_block_id = $this->game->blockchain->last_block_id()+1;
			$current_round = $this->game->block_to_round($mining_block_id);
			
			if ($round_id == $current_round) {
				$q = "SELECT SUM(".$this->game->db_game['payout_weight']."_score), SUM(unconfirmed_".$this->game->db_game['payout_weight']."_score) FROM options WHERE event_id='".$this->db_event['event_id']."';";
				$r = $this->game->blockchain->app->run_query($q);
				$r = $r->fetch();
				$score = $r['SUM('.$this->game->db_game['payout_weight'].'_score)']+$r['SUM(unconfirmed_'.$this->game->db_game['payout_weight'].'_score)'];
			}
			else {
				$q = "SELECT SUM(".$this->game->db_game['payout_weight']."_score) FROM event_outcome_options WHERE event_id='".$this->db_event['event_id']."' AND round_id='".$round_id."';";
				$r = $this->game->blockchain->app->run_query($q);
				$r = $r->fetch();
				$score = $r["SUM(".$this->game->db_game['payout_weight']."_score)"];
			}
			
			return $score/$this->game->blockchain->app->votes_per_coin($this->game->db_game);
		}
	}
	
	public function set_outcome_from_db($round_id, $last_block_id, $add_payout_transaction) {
		$round_voting_stats_all = $this->round_voting_stats_all($round_id);
		
		$sum_votes = $round_voting_stats_all[0];
		$max_sum_votes = $round_voting_stats_all[1];
		$option_id2rank = $round_voting_stats_all[3];
		$round_voting_stats = $round_voting_stats_all[2];
		
		$winning_option = FALSE;
		$winning_votes = 0;
		for ($rank=0; $rank<count($round_voting_stats); $rank++) {
			$option_id = $round_voting_stats[$rank]['option_id'];
			$option_rank2db_id[$rank] = $option_id;
			$option_votes = $this->option_votes_in_round($option_id, $round_id);
			
			if ($option_votes['sum'] > $max_sum_votes) {}
			else if (!$winning_option && $option_votes['sum'] > 0) {
				$winning_option = $option_id;
				$winning_votes = $option_votes['sum'];
			}
		}
		
		$log_text = "Total votes: ".($sum_votes/(pow(10, 8)))."<br/>\n";
		$log_text .= "Cutoff: ".($max_sum_votes/(pow(10, 8)))."<br/>\n";
		
		$q = "UPDATE options SET coin_score=0, unconfirmed_coin_score=0, coin_block_score=0, unconfirmed_coin_block_score=0, coin_round_score=0, unconfirmed_coin_round_score=0, votes=0, unconfirmed_votes=0 WHERE event_id='".$this->db_event['event_id']."';";
		$r = $this->game->blockchain->app->run_query($q);
		
		$payout_transaction_id = false;
		
		if ($winning_option) {
			$q = "UPDATE options SET last_win_round=".$round_id." WHERE event_id='".$this->db_event['event_id']."' AND option_id='".$winning_option."';";
			$r = $this->game->blockchain->app->run_query($q);
			
			$log_text .= $round_voting_stats[$option_id2rank[$winning_option]]['name']." wins with ".($winning_votes/(pow(10, 8)))." votes.<br/>";
		}
		else $log_text .= "No winner<br/>";
		
		//if ($this->game->db_game['losable_bets_enabled'] == 1) {
		//	$betbase_response = $this->new_betbase_transaction($round_id, $last_block_id+1, $winning_option);
		//	$log_text .= $betbase_response[1];
		//}
		
		$q = "INSERT INTO event_outcomes SET event_id='".$this->db_event['event_id']."', round_id='".$round_id."', payout_block_id='".$last_block_id."'";
		if ($winning_option) $q .= ", winning_option_id='".$winning_option."', derived_winning_option_id='".$winning_option."'";
		$q .= ", winning_votes='".$winning_votes."', derived_winning_votes='".$winning_votes."', sum_votes='".$sum_votes."', time_created='".time()."';";
		$r = $this->game->blockchain->app->run_query($q);
		$outcome_id = $this->game->blockchain->app->last_insert_id();
		
		for ($position=0; $position<$this->db_event['num_voting_options']; $position++) {
			$qq = "INSERT INTO event_outcome_options SET outcome_id='".$outcome_id."', round_id='".$round_id."', event_id='".$this->db_event['event_id']."', option_id='".$option_rank2db_id[$position]."', rank='".($position+1)."', coin_score='".$round_voting_stats[$position]['coin_score']."', coin_block_score='".$round_voting_stats[$position]['coin_block_score']."', coin_round_score='".$round_voting_stats[$position]['coin_round_score']."', votes='".$round_voting_stats[$position]['votes']."';";
			$rr = $this->game->blockchain->app->run_query($qq);
		}
		
		if ($winning_option && $add_payout_transaction) {
			$payout_response = $this->new_payout_transaction($round_id, $last_block_id, $winning_option, $winning_votes);
			
			$log_text .= "Payout response: ".$payout_response;
			$log_text .= "<br/>\n";
		}
		
		if ($this->game->db_game['send_round_notifications'] == 1) {
			$this->game->send_round_notifications($round_id, $round_voting_stats_all);
		}
		
		$this->set_event_completed();
		
		return $log_text;
	}
	
	public function user_winnings_description($user_id, $round_id, $event_status, $winning_option_id, $winning_votes, $winning_option_name, &$my_votes) {
		$txt = "";
		$include_unconfirmed = false;
		if ($event_status == "current") $include_unconfirmed = true;
		
		$returnvals = $this->my_votes_in_round($round_id, $user_id, $include_unconfirmed);
		$my_votes = $returnvals[0];
		$coins_voted = $returnvals[1];
		
		if (!empty($my_votes[$winning_option_id])) {
			$payout_amt = $this->event_pos_reward_in_round($round_id)/pow(10,8)*$my_votes[$winning_option_id]['votes']/$winning_votes;
			$payout_disp = $this->game->blockchain->app->format_bignum($payout_amt);
			$txt .= "You won <font class=\"greentext\">+".$payout_disp." ";
			if ($payout_disp == '1') $txt .= $this->game->db_game['coin_name'];
			else $txt .= $this->game->db_game['coin_name_plural'];
			
			$vote_disp = $this->game->blockchain->app->format_bignum($my_votes[$winning_option_id]['votes']/pow(10,8));
			$txt .= "</font> in ".$this->db_event['event_name']." by casting ".$vote_disp." vote";
			if ($vote_disp != "1") $txt .= "s";
			
			$txt .= " for ".$winning_option_name.".";
		}
		else {
			if ($winning_option_id) {
				$txt = "You did not cast any votes for ".$winning_option_name.".";
			}
			else {
				if ($event_status == "current") {
					$txt = "The outcome of this round has not yet been determined.";
				}
				else $txt = "You didn't cast any winning votes in this round.";
			}
		}
		return $txt;
	}
}
?>
